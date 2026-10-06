<?php

use App\AI\AiModelRouteService;
use App\AI\AiSettingsResolver;
use App\Enums\AiStage;
use App\Filament\Resources\Novels\Pages\CreateNovel;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Models\AIModelPrice;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
        'context_window_tokens' => 1_050_000,
        'max_output_tokens' => 128_000,
        'supports_structured_output' => true,
        'supports_reasoning_effort' => true,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);
}

test('a novel outline override rejects an unverified model profile', function () {
    $price = AIModelPrice::query()->create([
        'provider' => 'openai',
        'model' => 'unverified-novel-outline-model',
        'currency' => 'USD',
        'billing_unit' => 1_000_000,
        'input_price' => 1,
        'output_price' => 2,
        'is_enabled' => true,
    ]);

    expect(fn () => app(AiModelRouteService::class)->applyNovelOverrides([], [
        AiStage::OutlineFoundation->value => $price->getKey(),
    ]))->toThrow(ValidationException::class, '缺少已核实');
});

test('novel settings shows environment and overridden resolved models', function () {
    $writerPrice = novelAiModelPrice('novel-writer');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => 'novel-writer']]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('AI 模型覆盖')
        ->assertSee('章节规划 · 当前表单生效路由')
        ->assertSee('OPENAI / global-planner · 推理 Provider 默认 · 环境路由')
        ->assertSee('场景写作 · 当前表单生效路由')
        ->assertSee('OPENAI / novel-writer · 推理 Provider 默认 · 小说 Override')
        ->assertFormSet([
            'ai_model_overrides.writer' => $writerPrice->getKey(),
        ]);
});

test('novel form remains usable when inherited outline routes are missing and previews the selected override', function () {
    foreach ([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ] as $stage) {
        config()->set("ai.models.{$stage->value}", null);
        config()->set("ai.stage_providers.{$stage->value}", null);
    }
    $foundationPrice = novelAiModelPrice('foundation-form-model');

    Livewire::test(CreateNovel::class)
        ->assertOk()
        ->assertSee('继承路由不可用 · 请选择 Provider + Model')
        ->fillForm([
            'ai_model_overrides.outline_foundation' => $foundationPrice->getKey(),
        ])
        ->assertSee('OPENAI / foundation-form-model · 推理 Provider 默认 · 小说 Override');
});

test('creating a novel persists provider and model overrides for every outline task', function () {
    config()->set('ai.providers.deepseek.enabled', true);
    config()->set('ai.providers.deepseek.api_key', 'deepseek-test-key');
    $prices = collect([
        'outline_foundation' => ['provider' => 'openai', 'model' => 'foundation-model'],
        'outline_structure' => ['provider' => 'deepseek', 'model' => 'structure-model'],
        'outline_arc_beats' => ['provider' => 'openai', 'model' => 'arc-beats-model'],
        'outline_beat_detail' => ['provider' => 'openai', 'model' => 'beat-detail-model'],
    ])->map(fn (array $route): int => novelAiModelPrice($route['model'], $route['provider'])->getKey());

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
        'outline_structure' => ['provider' => 'deepseek', 'model' => 'structure-model'],
        'outline_arc_beats' => ['provider' => 'openai', 'model' => 'arc-beats-model'],
        'outline_beat_detail' => ['provider' => 'openai', 'model' => 'beat-detail-model'],
    ]);

    $resolver = app(AiSettingsResolver::class);
    foreach ([
        [AiStage::OutlineFoundation, 'openai', 'foundation-model'],
        [AiStage::OutlineStructure, 'deepseek', 'structure-model'],
        [AiStage::OutlineArcBeats, 'openai', 'arc-beats-model'],
        [AiStage::OutlineBeatDetail, 'openai', 'beat-detail-model'],
    ] as [$stage, $provider, $model]) {
        $resolved = $resolver->resolve($stage, $novel);

        expect($resolved->provider)->toBe($provider)
            ->and($resolved->model)->toBe($model)
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

test('legacy outline model-only override remains visible without inferring a provider and migrates only after reselection', function () {
    $replacement = novelAiModelPrice('legacy-outline-model');
    $novel = Novel::factory()->create([
        'settings' => [
            'temperature' => 0.4,
            'ai' => ['models' => ['outline_foundation' => 'legacy-outline-model']],
        ],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertSee('当前旧配置不完整 · legacy-outline-model · 请重新选择 Provider + Model')
        ->assertSee('配置不完整 · legacy-outline-model')
        ->assertFormSet(['ai_model_overrides.outline_foundation' => 'incomplete-outline:outline_foundation'])
        ->fillForm(['title' => '仅修改标题'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'ai.models.outline_foundation'))->toBe('legacy-outline-model')
        ->and(data_get($novel->settings, 'ai.stages.outline_foundation'))->toBeNull()
        ->and(data_get($novel->settings, 'temperature'))->toBe(0.4);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm(['ai_model_overrides.outline_foundation' => $replacement->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'ai.models.outline_foundation'))->toBeNull()
        ->and(data_get($novel->settings, 'ai.stages.outline_foundation'))->toBe([
            'provider' => 'openai',
            'model' => 'legacy-outline-model',
        ]);
});

test('existing outline reasoning survives an unrelated save and resets only when the route changes', function () {
    $currentPrice = novelAiModelPrice('current-outline-model');
    $replacementPrice = novelAiModelPrice('replacement-outline-model');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['stages' => ['outline_foundation' => [
            'provider' => 'openai',
            'model' => 'current-outline-model',
            'reasoning_effort' => 'high',
        ]]]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertFormSet(['ai_model_overrides.outline_foundation' => $currentPrice->getKey()])
        ->assertSee('OPENAI / current-outline-model · 推理 high · 小说 Override')
        ->fillForm(['title' => '保留推理配置'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'ai.stages.outline_foundation.reasoning_effort'))->toBe('high');

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->fillForm(['ai_model_overrides.outline_foundation' => $replacementPrice->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'ai.stages.outline_foundation'))->toBe([
        'provider' => 'openai',
        'model' => 'replacement-outline-model',
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
