<?php

use App\AI\AiSettingsResolver;
use App\Enums\AiStage;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Models\AIModelPrice;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config()->set('ai.models.planner', 'global-planner');
    config()->set('ai.models.writer', 'global-writer');
    config()->set('ai.providers.openai.api_key', 'test-key');
});

function novelAiModelPrice(string $model, string $provider = 'openai'): AIModelPrice
{
    return AIModelPrice::query()->create([
        'provider' => $provider,
        'model' => $model,
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
}

test('novel settings shows environment and overridden resolved models', function () {
    $writerPrice = novelAiModelPrice('novel-writer');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => 'novel-writer']]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('AI Model Overrides')
        ->assertSee('章节规划 Resolved Model')
        ->assertSee('global-planner · Environment Default')
        ->assertSee('场景写作 Resolved Model')
        ->assertSee('novel-writer · Novel Override')
        ->assertFormSet([
            'ai_model_overrides.writer' => $writerPrice->getKey(),
        ]);
});

test('creating a novel persists provider and model overrides for every outline task', function () {
    $prices = collect([
        'outline_foundation' => 'foundation-model',
        'outline_structure' => 'structure-model',
        'outline_arc_beats' => 'arc-beats-model',
        'outline_beat_detail' => 'beat-detail-model',
    ])->map(fn (string $model): int => novelAiModelPrice($model)->getKey());

    Livewire::test(CreateNovel::class)
        ->fillForm([
            'title' => '逐路由新书',
            'genre' => '玄幻奇幻',
            'premise' => '用于验证新建小说模型覆盖。',
            'target_words' => 1_000_000,
            'generation_chapter_target_words' => 3_000,
            'ai_model_overrides' => [
                'outline_foundation' => $prices['outline_foundation'],
                'outline_structure' => $prices['outline_structure'],
                'outline_arc_beats' => $prices['outline_arc_beats'],
                'outline_beat_detail' => $prices['outline_beat_detail'],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $novel = Novel::query()->sole();

    expect(data_get($novel->settings, 'ai.stages'))->toBe([
        'outline_foundation' => ['provider' => 'openai', 'model' => 'foundation-model'],
        'outline_structure' => ['provider' => 'openai', 'model' => 'structure-model'],
        'outline_arc_beats' => ['provider' => 'openai', 'model' => 'arc-beats-model'],
        'outline_beat_detail' => ['provider' => 'openai', 'model' => 'beat-detail-model'],
    ]);

    $resolver = app(AiSettingsResolver::class);
    foreach ([
        [AiStage::OutlineFoundation, 'foundation-model'],
        [AiStage::OutlineStructure, 'structure-model'],
        [AiStage::OutlineArcBeats, 'arc-beats-model'],
        [AiStage::OutlineBeatDetail, 'beat-detail-model'],
    ] as [$stage, $model]) {
        $resolved = $resolver->resolve($stage, $novel);

        expect($resolved->model)->toBe($model)
            ->and($resolved->source)->toBe('novel');
    }
});

test('saving novel model overrides preserves unrelated settings and removes blank overrides', function () {
    $plannerPrice = novelAiModelPrice('new-planner');
    $novel = Novel::factory()->create([
        'settings' => [
            'temperature' => 0.4,
            'ai' => [
                'custom_option' => true,
                'models' => [
                    'planner' => 'old-planner',
                    'writer' => 'old-writer',
                ],
            ],
        ],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm([
            'ai_model_overrides' => [
                'planner' => $plannerPrice->getKey(),
                'writer' => null,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($novel->refresh()->settings)->toBe([
        'temperature' => 0.4,
        'ai' => [
            'custom_option' => true,
            'stages' => [
                'planner' => ['provider' => 'openai', 'model' => 'new-planner'],
            ],
        ],
        'auto_commit' => false,
        'auto_commit_configured' => true,
    ]);
});

test('an unregistered legacy override remains selectable and survives an unrelated save', function () {
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => 'legacy-private-model']]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('当前旧配置 · OPENAI · legacy-private-model')
        ->assertFormSet(['ai_model_overrides.writer' => 'existing:writer'])
        ->fillForm(['title' => '保留旧模型'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'ai.models.writer'))->toBeNull()
        ->and(data_get($novel->settings, 'ai.stages.writer'))->toBe([
            'provider' => 'openai',
            'model' => 'legacy-private-model',
        ]);
});

test('novel settings exposes explicit automatic canonical commit and ignores the legacy key', function () {
    $novel = Novel::factory()->create(['settings' => ['auto_commit' => true]]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('Review 通过后自动提交正式章节')
        ->assertFormSet(['workflow_auto_commit' => false])
        ->fillForm(['workflow_auto_commit' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'auto_commit'))->toBeTrue()
        ->and(data_get($novel->settings, 'auto_commit_configured'))->toBeTrue();
});
