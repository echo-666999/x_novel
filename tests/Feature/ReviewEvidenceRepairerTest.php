<?php

use App\AI\Exceptions\AiProviderException;
use App\Models\GenerationArtifact;
use App\Models\Chapter;
use App\Models\Scene;
use App\Services\ReviewEvidenceRepairer;

test('review evidence repair only requests citations absent from the authoritative draft', function () {
    $draft = new GenerationArtifact(['content' => '刘据重新含乳，哭声渐渐低了。卫子夫命人记下时辰。']);
    $payload = [
        'chapter_plan_completion' => ['status' => 'fulfilled', 'evidence' => '完成乳具对照并留下可追溯记录。'],
        'milestone_completion' => [
            ['status' => 'fulfilled', 'evidence' => '“刘据重新含乳，哭声渐渐低了。”'],
        ],
        'beat_exit' => [['status' => 'not_met', 'evidence' => null]],
    ];
    $repairer = app(ReviewEvidenceRepairer::class);
    $invalid = $repairer->invalidAudits($payload, $draft, ['chapter_plan' => ['chapter_function' => '完成乳具对照']]);

    expect($invalid)->toHaveCount(1)
        ->and($invalid[0]['path'])->toBe('chapter_plan_completion');

    $repaired = $repairer->apply($payload, $invalid, [['evidence' => '卫子夫命人记下时辰。']], $draft);
    expect(data_get($repaired, 'chapter_plan_completion.status'))->toBe('fulfilled')
        ->and(data_get($repaired, 'chapter_plan_completion.evidence'))->toBe('卫子夫命人记下时辰。');
});

test('unverifiable fulfilled evidence becomes unmet but unverifiable conflict never disappears', function () {
    $draft = new GenerationArtifact(['content' => '正文只有这一句。']);
    $repairer = app(ReviewEvidenceRepairer::class);
    $payload = ['milestone_completion' => [['status' => 'fulfilled', 'evidence' => '不存在的原文']]];
    $invalid = $repairer->invalidAudits($payload, $draft, []);
    $repaired = $repairer->apply($payload, $invalid, [['evidence' => null]], $draft);

    expect(data_get($repaired, 'milestone_completion.0.status'))->toBe('not_met')
        ->and(data_get($repaired, 'milestone_completion.0.evidence'))->toBeNull();

    $payload['milestone_completion'][0]['status'] = 'contradicted';
    $invalid = $repairer->invalidAudits($payload, $draft, []);
    expect(fn () => $repairer->apply($payload, $invalid, [['evidence' => null]], $draft))
        ->toThrow(AiProviderException::class);
});

test('arc evidence must come from its planned scene, not another scene in the chapter', function () {
    $first = new Scene(['sequence' => 1]);
    $first->setRelation('currentArtifact', new GenerationArtifact(['content' => '刘据命人停用乳具。']));
    $second = new Scene(['sequence' => 2]);
    $second->setRelation('currentArtifact', new GenerationArtifact(['content' => '卫子夫记下时辰。']));
    $chapter = new Chapter;
    $chapter->setRelation('scenes', collect([$first, $second]));
    $draft = new GenerationArtifact(['content' => '刘据命人停用乳具。卫子夫记下时辰。']);
    $payload = ['arc_beat_audits' => [['status' => 'fulfilled', 'evidence' => '卫子夫记下时辰。']]];
    $context = ['chapter_plan' => ['arc_contributions' => [[
        'target_scene_sequence' => 1, 'acceptance_criteria' => '停用乳具',
    ]]]];
    $repairer = app(ReviewEvidenceRepairer::class);

    $invalid = $repairer->invalidAudits($payload, $draft, $context, $chapter);
    expect($invalid)->toHaveCount(1)
        ->and($invalid[0]['target_scene_sequence'])->toBe(1);

    $repaired = $repairer->apply($payload, $invalid, [['evidence' => '刘据命人停用乳具。']], $draft);
    expect(data_get($repaired, 'arc_beat_audits.0.evidence'))->toBe('刘据命人停用乳具。');

    $unproven = $repairer->apply($payload, $invalid, [['evidence' => '卫子夫记下时辰。']], $draft);
    expect(data_get($unproven, 'arc_beat_audits.0.status'))->toBe('missing');
});
