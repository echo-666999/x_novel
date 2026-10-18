<?php

use App\AI\AiModelRouteService;
use App\AI\AiSettingsResolver;
use App\Enums\AiStage;
use App\Filament\Resources\Novels\Pages\EditNovel;
use App\Models\AIModelPrice;
use App\Models\Novel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    createAiProviderConnectionForTest();
    createAiModelRouteForTest(AiStage::Planner, 'global-planner');
    createAiModelRouteForTest(AiStage::Writer, 'global-writer');
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

test('novel settings shows database and overridden resolved models', function () {
    $writerPrice = novelAiModelPrice('novel-writer');
    $novel = Novel::factory()->create([
        'settings' => ['ai' => ['models' => ['writer' => 'novel-writer']]],
    ]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('AI 模型覆盖')
        ->assertSee('章节规划 · 当前表单生效路由')
        ->assertSee('OPENAI / global-planner · 推理 Provider 默认 · 数据库路由')
        ->assertSee('场景写作 · 当前表单生效路由')
        ->assertSee('OPENAI / novel-writer · 推理 Provider 默认 · 小说 Override')
        ->assertFormSet([
            'ai_model_overrides.writer' => $writerPrice->getKey(),
        ]);
});

test('legacy novel form remains usable when inherited outline routes are missing and previews the selected override', function () {
    foreach ([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ] as $stage) {
        // 不建立该 Stage 的数据库路由，用于验证表单能够明确显示缺失配置。
    }
    $foundationPrice = novelAiModelPrice('foundation-form-model');

    // 旧模型覆盖表单仅保留在旧小说编辑页；新建入口使用统一全局路由。
    $novel = Novel::factory()->create();
    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertOk()
        ->assertSee('继承路由不可用 · 请选择 Provider + Model')
        ->fillForm([
            'ai_model_overrides.outline_foundation' => $foundationPrice->getKey(),
        ])
        ->assertSee('OPENAI / foundation-form-model · 推理 Provider 默认 · 小说 Override');
});

test('editing a legacy novel persists provider and model overrides for every outline task', function () {
    seedAllAiModelRoutesForTest();
    createAiProviderConnectionForTest('deepseek');
    $prices = collect([
        'outline_foundation' => ['provider' => 'openai', 'model' => 'foundation-model'],
        'outline_structure' => ['provider' => 'deepseek', 'model' => 'structure-model'],
        'outline_arc_beats' => ['provider' => 'openai', 'model' => 'arc-beats-model'],
        'outline_beat_detail' => ['provider' => 'openai', 'model' => 'beat-detail-model'],
    ])->map(fn (array $route): int => novelAiModelPrice($route['model'], $route['provider'])->getKey());

    // 旧模型覆盖表单仅保留在旧小说编辑页；新建入口使用统一全局路由。
    $novel = Novel::factory()->create();
    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
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
        ->call('save')
        ->assertHasNoFormErrors();

    $novel->refresh();

    expect(data_get($novel->settings, 'ai.stages'))->toEqual([
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

    // PostgreSQL JSONB不保留键顺序，模型设置断言只比较其语义并保留费用审计。
    expect(Arr::except($novel->refresh()->settings, ['budget', 'budget_history']))->toEqual([
        'temperature' => 0.4,
        'ai' => [
            'custom_option' => true,
            'stages' => [
                'planner' => ['provider' => 'openai', 'model' => 'new-planner'],
            ],
        ],
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
        ->and(data_get($novel->settings, 'ai.stages.writer'))->toEqual([
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
        ->and(data_get($novel->settings, 'ai.stages.outline_foundation'))->toEqual([
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

    expect(data_get($novel->refresh()->settings, 'ai.stages.outline_foundation'))->toEqual([
        'provider' => 'openai',
        'model' => 'replacement-outline-model',
    ]);
});

test('novel settings preserves an unconfirmed legacy auto commit key until a launch mode is selected', function () {
    $novel = Novel::factory()->create(['settings' => ['auto_commit' => true]]);

    Livewire::test(EditNovel::class, ['record' => $novel->getRouteKey()])
        ->assertDontSee('Review 通过后自动提交正式章节')
        ->fillForm(['title' => '保留旧运行配置'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(data_get($novel->refresh()->settings, 'auto_commit'))->toBeTrue()
        ->and(data_get($novel->settings, 'auto_commit_configured'))->toBeNull();
});
