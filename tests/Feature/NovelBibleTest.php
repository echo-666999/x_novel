<?php

use App\Actions\Novels\CreateBibleVersionAction;
use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

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
    ], $overrides);
}

test('bible json fields and status are cast to domain values', function () {
    $bible = NovelBible::factory()->create(bibleData())->fresh();

    expect($bible->themes)->toBe(['成长', '选择'])
        ->and($bible->taboos)->toBe(['机械降神'])
        ->and($bible->hard_constraints)->toBe(['主角不能复活'])
        ->and($bible->ending_contract['final_protagonist_state'])->toBe('成为故乡的守护者')
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
    ]));

    expect($first->refresh()->version)->toBe(1)
        ->and($first->status)->toBe(BibleStatus::Superseded)
        ->and($first->logline)->toBe('一个失去故乡的少年必须在复仇与守护之间作出选择。')
        ->and($first->themes)->toBe(['成长', '选择'])
        ->and($second->version)->toBe(2)
        ->and($second->status)->toBe(BibleStatus::Current)
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

test('the novel bibles migration can be rolled back', function () {
    expect(Schema::hasTable('novel_bibles'))->toBeTrue();

    $migration = require database_path('migrations/2026_09_06_100000_create_novel_bibles_table.php');
    $migration->down();

    expect(Schema::hasTable('novel_bibles'))->toBeFalse();
});
