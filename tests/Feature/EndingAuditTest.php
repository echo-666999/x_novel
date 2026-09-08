<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Jobs\EndingAuditJob;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\NovelBible;
use App\Models\StoryStateVersion;
use App\Services\EndingAuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('ending audit passes when every deterministic ending check has evidence', function () {
    $novel = endingAuditNovel();

    $artifact = app(EndingAuditService::class)->audit($novel);

    expect($artifact->type)->toBe(ArtifactType::EndingAudit)
        ->and($artifact->data['decision'])->toBe('PASS')
        ->and(collect($artifact->data['checks'])->pluck('key')->all())->toBe([
            'ending_contract',
            'critical_closure_debt',
            'foreshadowing',
            'story_arc',
            'character_arc',
            'state_gaps',
        ])
        ->and(collect($artifact->data['checks'])->pluck('status')->unique()->all())->toBe(['PASS'])
        ->and($artifact->generationRun->stage)->toBe(GenerationStage::EndingAudit);
});

test('critical closure debt blocks the audit and cannot complete the novel', function () {
    $novel = endingAuditNovel([
        'reader_promises' => [
            'origin' => ['title' => '揭示火种来源', 'status' => 'open', 'importance' => 'critical'],
        ],
    ]);

    $artifact = app(EndingAuditService::class)->audit($novel);
    $debtCheck = collect($artifact->data['checks'])->firstWhere('key', 'critical_closure_debt');

    expect($artifact->data['decision'])->toBe('BLOCK')
        ->and($debtCheck['status'])->toBe('BLOCK')
        ->and($debtCheck['evidence'])->toContain('未兑现读者承诺：揭示火种来源')
        ->and($novel->fresh()->status)->toBe(NovelStatus::Completing);
});

test('duplicate ending audit jobs reuse the immutable result', function () {
    $novel = endingAuditNovel();
    $job = new EndingAuditJob($novel->getKey());

    $job->handle(app(EndingAuditService::class));
    $job->handle(app(EndingAuditService::class));

    expect($job->queue)->toBe('default')
        ->and(GenerationRun::query()->where('stage', GenerationStage::EndingAudit)->count())->toBe(1)
        ->and(GenerationArtifact::query()->where('type', ArtifactType::EndingAudit)->count())->toBe(1);
});

test('ending audit rejects a novel outside completing mode without persisting a run', function () {
    $novel = Novel::factory()->create(['status' => NovelStatus::Generating]);

    expect(fn () => app(EndingAuditService::class)->audit($novel))
        ->toThrow(ValidationException::class)
        ->and(GenerationRun::query()->count())->toBe(0);
});

/** @param array<string, mixed> $stateOverrides */
function endingAuditNovel(array $stateOverrides = []): Novel
{
    $novel = Novel::factory()->create(['status' => NovelStatus::Completing]);
    NovelBible::factory()->for($novel)->create();
    $state = StoryStateVersion::factory()->for($novel)->create([
        'state' => array_replace_recursive([
            'schema_version' => 1,
            'characters' => [],
            'relationships' => [],
            'locations' => [],
            'items' => [],
            'world' => ['crises' => []],
            'timeline' => [],
            'open_threads' => [],
            'foreshadowings' => [],
            'reader_promises' => [],
            'character_arcs' => [
                ['title' => '主角成长弧', 'status' => 'completed'],
            ],
        ], $stateOverrides),
    ]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);

    return $novel->fresh();
}
