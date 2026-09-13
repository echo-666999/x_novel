<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Enums\SceneStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\Scene;
use App\Services\RewriteScopeResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function scopeFinding(string $scope, ?int $sceneId, string $evidence = '目标证据'): array
{
    return [
        'code' => 'STYLE_MISMATCH',
        'dimension' => 'style',
        'severity' => 'error',
        'scene_id' => $sceneId,
        'scope' => $scope,
        'auto_fixable' => true,
        'requires_human_decision' => false,
        'message' => '需要定向修复。',
        'evidence' => $evidence,
    ];
}

function scopeScene(Chapter $chapter, int $sequence, string $content): Scene
{
    $scene = Scene::factory()->for($chapter)->create([
        'sequence' => $sequence,
        'status' => SceneStatus::Draft,
    ]);
    $run = GenerationRun::factory()->for($chapter->novel)->for($chapter)->create([
        'scene_id' => $scene->getKey(),
        'scope_type' => 'scene',
        'scope_id' => $scene->getKey(),
        'stage' => GenerationStage::SceneGeneration,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::SceneDraft,
        'content' => $content,
        'checksum' => hash('sha256', $content),
    ]);
    $scene->update(['current_artifact_id' => $artifact->getKey()]);

    return $scene->refresh();
}

test('one local scene finding selects only that scene', function () {
    $chapter = Chapter::factory()->for(Novel::factory())->create();
    $scene = scopeScene($chapter, 1, '这是目标证据。');

    $decision = app(RewriteScopeResolver::class)->resolve(
        $chapter,
        [scopeFinding('scene', $scene->getKey())],
    );

    expect($decision->scope)->toBe('scene')
        ->and($decision->sceneId)->toBe($scene->getKey())
        ->and($decision->reason)->toBe('single_scene_affected');
});

test('paragraph evidence is mapped only when exactly one current scene contains it', function () {
    $chapter = Chapter::factory()->for(Novel::factory())->create();
    $target = scopeScene($chapter, 1, '林舟守住城门。');
    scopeScene($chapter, 2, '援军在远处集结。');

    $decision = app(RewriteScopeResolver::class)->resolve(
        $chapter,
        [scopeFinding('paragraph', null, '守住城门')],
    );

    expect($decision->scope)->toBe('scene')
        ->and($decision->sceneId)->toBe($target->getKey());
});

test('findings in multiple scenes select chapter rewrite', function () {
    $chapter = Chapter::factory()->for(Novel::factory())->create();
    $first = scopeScene($chapter, 1, '第一场景。');
    $second = scopeScene($chapter, 2, '第二场景。');

    $decision = app(RewriteScopeResolver::class)->resolve($chapter, [
        scopeFinding('scene', $first->getKey()),
        scopeFinding('scene', $second->getKey()),
    ]);

    expect($decision->scope)->toBe('chapter')
        ->and($decision->sceneId)->toBeNull()
        ->and($decision->reason)->toBe('multiple_scenes_affected');
});

test('chapter pacing finding selects chapter rewrite', function () {
    $chapter = Chapter::factory()->for(Novel::factory())->create();

    $decision = app(RewriteScopeResolver::class)->resolve(
        $chapter,
        [scopeFinding('chapter', null, '全章节奏失衡')],
    );

    expect($decision->scope)->toBe('chapter')
        ->and($decision->sceneId)->toBeNull()
        ->and($decision->reason)->toBe('chapter_finding_present');
});

test('ambiguous paragraph evidence does not select an arbitrary scene', function () {
    $chapter = Chapter::factory()->for(Novel::factory())->create();
    scopeScene($chapter, 1, '两人看向同一道门。');
    scopeScene($chapter, 2, '他们再次看向同一道门。');

    $decision = app(RewriteScopeResolver::class)->resolve(
        $chapter,
        [scopeFinding('paragraph', null, '同一道门')],
    );

    expect($decision->isResolved())->toBeFalse()
        ->and($decision->scope)->toBe('unresolved')
        ->and($decision->reason)->toBe('paragraph_evidence_not_unique');
});
