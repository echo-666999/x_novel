<?php

use App\Actions\Novels\CreateBibleVersionAction;
use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function bibleData(array $overrides = []): array
{
    return array_replace_recursive([
        'logline' => '一个失去故乡的少年必须在复仇与守护之间作出选择。',
        'themes' => ['成长', '选择'],
        'tone' => '克制而紧张',
        'pov' => '第三人称限知',
        'tense' => '过去时',
        'taboos' => ['机械降神'],
        'hard_constraints' => ['主角不能复活'],
        'ending_contract' => [
            'final_protagonist_state' => '成为故乡的守护者',
            'main_conflict_resolution' => '终结旧王朝的循环',
            'required_foreshadowing_payoff' => ['断剑的来历'],
        ],
        'style_profile' => [
            'subgenre' => '东方玄幻',
            'target_platform' => 'qidian',
            'primary_style' => 'passionate',
            'secondary_styles' => ['accessible_brisk'],
            'language_era' => 'modern_spoken',
            'pacing' => 'fast',
            'parameters' => [
                'ornateness' => 2,
                'dialogue_ratio' => 4,
                'description_density' => 3,
                'psychology_density' => 2,
                'humor_level' => 1,
                'literary_level' => 2,
            ],
        ],
    ], $overrides);
}

test('bible json fields and status are cast to domain values', function () {
    $bible = NovelBible::factory()->create(bibleData())->fresh();

    expect($bible->themes)->toBe(['成长', '选择'])
        ->and($bible->taboos)->toBe(['机械降神'])
        ->and($bible->hard_constraints)->toBe(['主角不能复活'])
        ->and($bible->ending_contract['final_protagonist_state'])->toBe('成为故乡的守护者')
        ->and($bible->style_profile)->toBe(bibleData()['style_profile'])
        ->and($bible->version)->toBeInt()
        ->and($bible->status)->toBe(BibleStatus::Current);
});

test('a novel cannot contain duplicate bible version numbers', function () {
    $novel = Novel::factory()->create();
    NovelBible::factory()->for($novel)->create(['version' => 1]);

    expect(fn () => NovelBible::factory()->for($novel)->create(['version' => 1]))
        ->toThrow(QueryException::class);
});

test('creating a new bible version advances current and preserves old content', function () {
    $novel = Novel::factory()->create();
    $action = app(CreateBibleVersionAction::class);

    $first = $action->execute($novel, bibleData());
    $second = $action->execute($novel, bibleData([
        'logline' => '少年选择守护故乡，并开始追查旧王朝的真相。',
        'themes' => ['成长', '责任'],
        'style_profile' => [
            'primary_style' => 'light_humorous',
            'secondary_styles' => ['accessible_brisk', 'delicate_emotional'],
            'parameters' => ['humor_level' => 4],
        ],
    ]));

    expect($first->refresh()->version)->toBe(1)
        ->and($first->status)->toBe(BibleStatus::Superseded)
        ->and($first->logline)->toBe('一个失去故乡的少年必须在复仇与守护之间作出选择。')
        ->and($first->themes)->toBe(['成长', '选择'])
        ->and($first->style_profile['primary_style'])->toBe('passionate')
        ->and($first->style_profile['secondary_styles'])->toBe(['accessible_brisk'])
        ->and($second->version)->toBe(2)
        ->and($second->status)->toBe(BibleStatus::Current)
        ->and($second->style_profile['primary_style'])->toBe('light_humorous')
        ->and($second->style_profile['secondary_styles'])->toBe(['accessible_brisk', 'delicate_emotional'])
        ->and($second->style_profile['parameters']['humor_level'])->toBe(4)
        ->and($novel->fresh()->currentBible->is($second))->toBeTrue()
        ->and($novel->bibles()->pluck('version')->all())->toBe([2, 1]);
});

test('bible version content cannot be updated or deleted', function () {
    $bible = NovelBible::factory()->create();

    expect(fn () => $bible->update(['logline' => '覆盖旧内容']))
        ->toThrow(LogicException::class, '请创建新版本')
        ->and(fn () => $bible->delete())
        ->toThrow(LogicException::class, '不可删除');
});

test('style profile cannot be updated in place', function () {
    $bible = NovelBible::factory()->create();
    $profile = $bible->style_profile;
    $profile['pacing'] = 'rapid';

    expect(fn () => $bible->update(['style_profile' => $profile]))
        ->toThrow(LogicException::class, '请创建新版本');
});

test('historical bible versions may keep a null style profile', function () {
    $bible = NovelBible::factory()->create(['style_profile' => null])->fresh();

    expect($bible->style_profile)->toBeNull();
});

test('new bible versions require a complete style profile after cgo 003', function () {
    $novel = Novel::factory()->create();
    $data = bibleData();
    unset($data['style_profile']);

    try {
        app(CreateBibleVersionAction::class)->execute($novel, $data);
        test()->fail('Expected the missing style profile to fail validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('style_profile')
            ->and(collect($exception->errors())->flatten()->implode(' '))->toContain('必须提供完整的文风设置');
    }

    expect($novel->bibles()->count())->toBe(0);
});

test('create bible version rejects invalid style profiles', function (Closure $mutate, string $errorKey) {
    $novel = Novel::factory()->create();
    $data = bibleData();
    $data['style_profile'] = $mutate($data['style_profile']);

    try {
        app(CreateBibleVersionAction::class)->execute($novel, $data);
        test()->fail('Expected style profile validation to fail.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey($errorKey)
            ->and($novel->bibles()->count())->toBe(0);
    }
})->with([
    'non-object value' => [fn () => 'invalid', 'style_profile'],
    'json array value' => [fn () => ['passionate'], 'style_profile'],
    'explicit null value' => [fn () => null, 'style_profile'],
    'missing subgenre key' => [function (array $profile): array {
        unset($profile['subgenre']);

        return $profile;
    }, 'style_profile.subgenre'],
    'unknown target platform' => [fn (array $profile) => array_replace($profile, ['target_platform' => 'unknown']), 'style_profile.target_platform'],
    'unknown primary style' => [fn (array $profile) => array_replace($profile, ['primary_style' => 'unknown']), 'style_profile.primary_style'],
    'unknown secondary style' => [fn (array $profile) => array_replace($profile, ['secondary_styles' => ['unknown']]), 'style_profile.secondary_styles.0'],
    'unknown language era' => [fn (array $profile) => array_replace($profile, ['language_era' => 'unknown']), 'style_profile.language_era'],
    'unknown pacing' => [fn (array $profile) => array_replace($profile, ['pacing' => 'unknown']), 'style_profile.pacing'],
    'more than two secondary styles' => [fn (array $profile) => array_replace($profile, ['secondary_styles' => ['accessible_brisk', 'austere', 'plain_realist']]), 'style_profile.secondary_styles'],
    'duplicate secondary styles' => [fn (array $profile) => array_replace($profile, ['secondary_styles' => ['austere', 'austere']]), 'style_profile.secondary_styles.1'],
    'primary repeated as secondary' => [fn (array $profile) => array_replace($profile, ['secondary_styles' => ['passionate']]), 'style_profile.secondary_styles'],
    'out-of-range parameter' => [fn (array $profile) => array_replace_recursive($profile, ['parameters' => ['ornateness' => 6]]), 'style_profile.parameters.ornateness'],
    'non-integer parameter' => [fn (array $profile) => array_replace_recursive($profile, ['parameters' => ['ornateness' => 2.5]]), 'style_profile.parameters.ornateness'],
]);

test('create bible version requires every style parameter', function () {
    $novel = Novel::factory()->create();
    $data = bibleData();
    unset($data['style_profile']['parameters']['literary_level']);

    expect(fn () => app(CreateBibleVersionAction::class)->execute($novel, $data))
        ->toThrow(ValidationException::class);
});

test('database rejects a non-object style profile on postgresql', function () {
    if (DB::getDriverName() !== 'pgsql') {
        test()->markTestSkipped('PostgreSQL-specific JSON object constraint.');
    }

    expect(fn () => NovelBible::factory()->create(['style_profile' => ['passionate']]))
        ->toThrow(QueryException::class);
});

test('the novel bibles migration can be rolled back', function () {
    expect(Schema::hasTable('novel_bibles'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_100000_create_novel_bibles_table.php');
    $migration->down();

    expect(Schema::hasTable('novel_bibles'))->toBeFalse();
});
