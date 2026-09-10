<?php

use App\Actions\Story\InitializeNovelStateAction;
use App\AI\Contracts\EmbeddingProvider;
use App\AI\Data\EmbeddingResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeEmbeddingProvider;
use App\Data\ContextRequest;
use App\Enums\ArtifactType;
use App\Enums\BibleStatus;
use App\Enums\ChapterStatus;
use App\Enums\FactStatus;
use App\Models\Chapter;
use App\Models\ChapterPlan;
use App\Models\Character;
use App\Models\Fact;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Models\WorldEntity;
use App\Services\ContextBuilder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException as ContextInvalidArgumentException;

uses(RefreshDatabase::class);

function contextFixture(array $bibleOverrides = []): array
{
    $novel = Novel::factory()->create();
    $state = app(InitializeNovelStateAction::class)->handle($novel);
    $bible = NovelBible::factory()->for($novel)->create([
        'version' => 3,
        'hard_constraints' => ['魔法不能复活死者'],
        'ending_contract' => ['主角必须回到故乡'],
        ...$bibleOverrides,
    ]);
    $chapter = Chapter::factory()->for($novel)->create(['sequence' => 20]);
    $character = Character::factory()->for($novel)->create();
    $lockedFact = Fact::factory()->for($novel)->create([
        'subject_type' => 'character',
        'subject_id' => $character->getKey(),
        'predicate' => 'can_swim',
        'value' => ['value' => false],
        'locked' => true,
        'status' => FactStatus::Active,
    ]);
    $world = WorldEntity::factory()->for($novel)->create([
        'name' => '潮汐门',
        'rules' => ['只能在月落时开启'],
        'locked_fields' => ['rules'],
    ]);
    $plan = ChapterPlan::factory()->for($chapter)->create([
        'pov_character_id' => $character->getKey(),
        'must_reveal' => ['潮汐门仍然存在'],
        'must_not_reveal' => ['守门人的真名'],
        'required_facts' => [$lockedFact->getKey()],
        'forbidden_conflicts' => ['角色不得突然会游泳'],
    ]);

    return compact('novel', 'state', 'bible', 'chapter', 'character', 'lockedFact', 'world', 'plan');
}

test('context builder freezes l0 and the requested canonical l1 with trace metadata', function () {
    $fixture = contextFixture();
    $snapshot = app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(),
        chapterId: $fixture['chapter']->getKey(),
        sceneId: null,
        taskType: 'scene_generation',
        bibleVersion: $fixture['bible']->version,
        stateVersion: 0,
        chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000,
        promptVersion: 'scene-writer-v1',
        model: 'writer-test',
    ));
    $data = $snapshot->toArray();

    expect($data['schema_version'])->toBe(2)
        ->and($data['bible_version'])->toBe(3)
        ->and($data['style_contract_checksum'])->toBe($data['l4']['checksum'])
        ->and($data['state_version'])->toBe(0)
        ->and($data['chapter_plan_id'])->toBe($fixture['plan']->getKey())
        ->and($data['prompt_version'])->toBe('scene-writer-v1')
        ->and($data['model'])->toBe('writer-test')
        ->and($data['l0']['bible_hard_constraints'])->toBe(['魔法不能复活死者'])
        ->and($data['l0']['ending_contract'])->toBe(['主角必须回到故乡'])
        ->and($data['l0']['locked_and_required_facts'][0]['id'])->toBe($fixture['lockedFact']->getKey())
        ->and($data['l0']['world_rules'][0]['id'])->toBe($fixture['world']->getKey())
        ->and($data['l1']['canonical_story_state'])->toBe($fixture['state']->state)
        ->and($data['fact_ids'])->toBe([$fixture['lockedFact']->getKey()])
        ->and($data['character_ids'])->toContain($fixture['character']->getKey())
        ->and($data['world_entity_ids'])->toBe([$fixture['world']->getKey()])
        ->and($data['memory_ids'])->toBe([])
        ->and($data['recent_chapter_ids'])->toBe([])
        ->and($data['l4']['bible_id'])->toBe($fixture['bible']->getKey())
        ->and($data['l4']['tone'])->toBe($fixture['bible']->tone)
        ->and($data['l4']['pov'])->toBe($fixture['bible']->pov)
        ->and($data['l4']['tense'])->toBe($fixture['bible']->tense)
        ->and($data['l4']['primary_style'])->toHaveKeys(['code', 'name', 'instruction'])
        ->and(data_get($data, 'l4.expanded_parameters.ornateness'))->toHaveKeys(['value', 'level', 'instruction'])
        ->and(data_get($data, 'l4.constraints.hard_constraints'))->toBe(['魔法不能复活死者'])
        ->and($data['token_allocation']['sections'])->toHaveKeys(['l0', 'l1', 'l2', 'l4'])
        ->and($data['truncated_sections'])->toBe([]);
});

test('l2 selects the configured canonical chapter window in chronological order', function () {
    config()->set('context.recent_chapter_window', 5);
    $fixture = contextFixture();
    $older = Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 14, 'status' => ChapterStatus::Canonical, 'summary' => '第十四章摘要',
    ]);
    $selected = collect(range(15, 19))->map(fn (int $sequence): Chapter => Chapter::factory()
        ->for($fixture['novel'])
        ->create([
            'sequence' => $sequence,
            'status' => ChapterStatus::Canonical,
            'summary' => "第 {$sequence} 章摘要",
        ]));
    Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 13, 'status' => ChapterStatus::Planned, 'summary' => '草稿摘要',
    ]);

    $snapshot = app(ContextBuilder::class)->build(contextRequest($fixture));

    expect($snapshot->recentChapterIds)->toBe($selected->pluck('id')->all())
        ->and(array_column($snapshot->l2['recent_chapters'], 'sequence'))->toBe([15, 16, 17, 18, 19])
        ->and($snapshot->l2['previous_chapter_ending']['text'])->toBe('第 19 章摘要')
        ->and($snapshot->l2['story_events'])->toBe([])
        ->and($snapshot->l2['story_events_status'])->toBe('pending_task_070')
        ->and($snapshot->recentChapterIds)->not->toContain($older->getKey());
});

test('l2 uses the previous canonical artifact ending when available', function () {
    config()->set('context.previous_chapter_ending_characters', 100);
    $fixture = contextFixture();
    $previous = Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 19, 'status' => ChapterStatus::Canonical, 'summary' => '备用摘要',
    ]);
    $run = GenerationRun::factory()->for($fixture['novel'])->for($previous)->create();
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => str_repeat('前', 120).'真正的章末悬念',
    ]);
    $previous->update(['canonical_artifact_id' => $artifact->getKey()]);

    $snapshot = app(ContextBuilder::class)->build(contextRequest($fixture));

    expect($snapshot->l2['previous_chapter_ending']['source'])->toBe('canonical_artifact')
        ->and($snapshot->l2['previous_chapter_ending']['text'])->toEndWith('真正的章末悬念')
        ->and(mb_strlen($snapshot->l2['previous_chapter_ending']['text']))->toBe(100);
});

test('l2 drops recent story before mandatory context when the budget is tight', function () {
    $fixture = contextFixture();
    Chapter::factory()->for($fixture['novel'])->create([
        'sequence' => 19,
        'status' => ChapterStatus::Canonical,
        'summary' => str_repeat('很长的近期剧情', 300),
    ]);
    $large = app(ContextBuilder::class)->build(contextRequest($fixture));
    $mandatory = $large->tokenAllocation->sections['l0'] + $large->tokenAllocation->sections['l1'] + $large->tokenAllocation->sections['l4'];

    $snapshot = app(ContextBuilder::class)->build(contextRequest($fixture, $mandatory));

    expect($snapshot->l0)->toBe($large->l0)
        ->and($snapshot->l1)->toBe($large->l1)
        ->and($snapshot->recentChapterIds)->toBe([])
        ->and($snapshot->l4)->toBe($large->l4)
        ->and($snapshot->tokenAllocation->sections)->toHaveKeys(['l0', 'l1', 'l4'])
        ->and($snapshot->tokenAllocation->truncatedSections)->toBe(['l2.recent_story']);
});

test('context builder assembles ranked long term memory into l3 and records selected ids', function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    $fixture = contextFixture();
    $memory = Memory::factory()->for($fixture['novel'])->create([
        'summary' => '主角曾在潮汐门获得旧王密钥。',
        'entities' => ['characters' => [(string) $fixture['character']->getKey()]],
        'embedding' => '[1,0,0]',
        'embedding_model' => 'embedding-fixed-v1',
        'valid_from_chapter' => 2,
    ]);
    app()->instance(EmbeddingProvider::class, (new FakeEmbeddingProvider)->enqueue(new EmbeddingResponse(
        embedding: [1.0, 0.0, 0.0], inputTokens: 3, latencyMs: 4, providerRequestId: null, model: 'embedding-fixed-v1',
    )));

    $snapshot = app(ContextBuilder::class)->build(contextRequest($fixture));

    expect($snapshot->memoryIds)->toBe([$memory->getKey()])
        ->and($snapshot->l3['status'])->toBe('ready')
        ->and($snapshot->l3['memories'][0]['summary'])->toBe('主角曾在潮汐门获得旧王密钥。')
        ->and($snapshot->tokenAllocation->sections)->toHaveKey('l3');
});

test('l3 retrieval failure preserves mandatory context and records the fallback', function () {
    config()->set('ai.embedding.model', 'embedding-fixed-v1');
    config()->set('ai.embedding.dimensions', 3);
    $fixture = contextFixture();
    Memory::factory()->for($fixture['novel'])->create([
        'entities' => ['characters' => [(string) $fixture['character']->getKey()]],
        'embedding' => '[1,0,0]',
        'embedding_model' => 'embedding-fixed-v1',
        'valid_from_chapter' => 2,
    ]);
    app()->instance(EmbeddingProvider::class, (new FakeEmbeddingProvider)->enqueue(
        new AiProviderException('provider_timeout', 'timeout', true),
    ));

    $snapshot = app(ContextBuilder::class)->build(contextRequest($fixture));

    expect($snapshot->l0)->not->toBeEmpty()
        ->and($snapshot->l1)->not->toBeEmpty()
        ->and($snapshot->memoryIds)->toBe([])
        ->and($snapshot->l3['status'])->toBe('unavailable')
        ->and($snapshot->tokenAllocation->truncatedSections)->toContain('l3.long_term_memory');
});

function contextRequest(array $fixture, int $tokenBudget = 10_000, ?int $bibleVersion = null): ContextRequest
{
    return new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', bibleVersion: $bibleVersion ?? $fixture['bible']->version,
        stateVersion: 0, chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: $tokenBudget, promptVersion: 'scene-writer-v1', model: 'writer-test',
    );
}

test('style contract checksum is stable and a requested bible version changes the frozen input', function () {
    $fixture = contextFixture();
    $builder = app(ContextBuilder::class);
    $first = $builder->build(contextRequest($fixture));
    $repeated = $builder->build(contextRequest($fixture));

    $fixture['bible']->update(['status' => BibleStatus::Superseded]);
    $nextBible = NovelBible::factory()->for($fixture['novel'])->create([
        'version' => 4,
        'tone' => '冷峻',
        'status' => BibleStatus::Current,
    ]);
    $stillFrozen = $builder->build(contextRequest($fixture));
    $next = $builder->build(contextRequest($fixture, bibleVersion: $nextBible->version));
    $inputHash = fn ($snapshot): string => hash('sha256', json_encode([
        'context' => $snapshot->toArray(),
        'model' => 'writer-test',
        'prompt_version' => 'scene-writer-v1',
        'regeneration_batch_id' => null,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

    expect($repeated->styleContractChecksum)->toBe($first->styleContractChecksum)
        ->and($stillFrozen->styleContractChecksum)->toBe($first->styleContractChecksum)
        ->and($stillFrozen->l4['bible_version'])->toBe(3)
        ->and($stillFrozen->l4['tone'])->toBe($fixture['bible']->tone)
        ->and($next->styleContractChecksum)->not->toBe($first->styleContractChecksum)
        ->and($next->l4['bible_version'])->toBe(4)
        ->and($next->l4['tone'])->toBe('冷峻')
        ->and($inputHash($next))->not->toBe($inputHash($first));
});

test('chapter pipeline keeps the bible version attached to its current plan', function () {
    $fixture = contextFixture();
    GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'bible_version' => $fixture['bible']->version,
        'context_snapshot' => ['chapter_plan_id' => $fixture['plan']->getKey()],
    ]);
    $fixture['bible']->update(['status' => BibleStatus::Superseded]);
    NovelBible::factory()->for($fixture['novel'])->create([
        'version' => 4,
        'status' => BibleStatus::Current,
    ]);

    expect(app(ContextBuilder::class)->bibleVersionForChapter($fixture['chapter']))
        ->toBe($fixture['bible']->version);
});

test('context builder rejects an invalid requested style profile without using another bible', function () {
    $fixture = contextFixture(['style_profile' => null]);

    app(ContextBuilder::class)->build(contextRequest($fixture));
})->throws(ValidationException::class, '必须提供完整的文风设置');

test('l1 comes from the requested immutable state version instead of projections', function () {
    $fixture = contextFixture();
    StoryStateVersion::factory()->for($fixture['novel'])->create([
        'version' => 1,
        'state' => ['timeline' => ['later']],
    ]);
    $fixture['character']->update(['current_state' => ['location' => '错误投影']]);

    $snapshot = app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', bibleVersion: $fixture['bible']->version,
        stateVersion: 0, chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    ));

    expect($snapshot->l1['canonical_story_state'])->toBe($fixture['state']->state)
        ->not->toHaveKey('characters.0.current_state');
});

test('context builder rejects chapter plans from another novel', function () {
    $fixture = contextFixture();
    $foreignPlan = ChapterPlan::factory()->create();

    app(ContextBuilder::class)->build(new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', bibleVersion: $fixture['bible']->version,
        stateVersion: 0, chapterPlanId: $foreignPlan->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    ));
})->throws(ModelNotFoundException::class);

test('context builder freezes the snapshot on its generation run', function () {
    $fixture = contextFixture();
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'bible_version' => null,
        'context_snapshot' => null,
    ]);
    $request = new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', bibleVersion: $fixture['bible']->version,
        stateVersion: 0, chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    );

    $snapshot = app(ContextBuilder::class)->buildForRun($run, $request);
    $run->refresh();

    expect($run->context_snapshot)->toBe($snapshot->toArray())
        ->and($run->state_version)->toBe(0)
        ->and($run->bible_version)->toBe(3)
        ->and($run->prompt_version)->toBe('scene-writer-v1')
        ->and($run->model_policy)->toBe('writer-test');
});

test('context builder refuses to attach a snapshot to an unrelated run', function () {
    $fixture = contextFixture();
    $foreignRun = GenerationRun::factory()->create();

    app(ContextBuilder::class)->buildForRun($foreignRun, new ContextRequest(
        novelId: $fixture['novel']->getKey(), chapterId: $fixture['chapter']->getKey(), sceneId: null,
        taskType: 'scene_generation', bibleVersion: $fixture['bible']->version,
        stateVersion: 0, chapterPlanId: $fixture['plan']->getKey(),
        tokenBudget: 10_000, promptVersion: 'scene-writer-v1', model: 'writer-test',
    ));
})->throws(ContextInvalidArgumentException::class, 'ContextRequest 与 GenerationRun');

test('context builder refuses to replace a run frozen to another bible version', function () {
    $fixture = contextFixture();
    $run = GenerationRun::factory()->for($fixture['novel'])->for($fixture['chapter'])->create([
        'scene_id' => null,
        'bible_version' => 2,
        'context_snapshot' => null,
    ]);

    app(ContextBuilder::class)->buildForRun($run, contextRequest($fixture));
})->throws(ContextInvalidArgumentException::class, 'Bible Version 不一致');
