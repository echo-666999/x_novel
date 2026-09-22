<?php

use App\Models\StoryArc;
use App\Services\OutlineSchemaNormalizer;
use App\Services\StoryArcBeatContract;

test('legacy string beats keep the existing computed key contract', function () {
    $contract = new StoryArcBeatContract;
    $normalizer = new OutlineSchemaNormalizer($contract);

    $beats = $normalizer->normalizeBeats(['  与苏璃   结识  ']);

    expect($beats)->toHaveCount(1)
        ->and($beats[0]['key'])->toBe($contract->key('  与苏璃   结识  '))
        ->and($beats[0]['sequence'])->toBe(1)
        ->and($beats[0]['chapter_budget'])->toBe(['min' => 1, 'max' => null])
        ->and($beats[0]['title'])->toBe('  与苏璃   结识  ')
        ->and($beats[0]['acceptance_criteria'])->toBe(['  与苏璃   结识  ']);
});

test('structured beats preserve their explicit key when title copy changes', function () {
    $normalizer = new OutlineSchemaNormalizer(new StoryArcBeatContract);

    $original = $normalizer->normalizeBeats([[
        'key' => 'academy-professor-training',
        'sequence' => 2,
        'title' => '教授指导',
        'summary' => '教授开始指导林墨。',
        'chapter_budget' => ['min' => 2, 'max' => 4],
        'acceptance_criteria' => ['教授正式登场'],
        'must_include' => ['先考察林墨'],
        'character_candidates' => [['candidate_key' => 'character-professor']],
    ]]);
    $renamed = $normalizer->normalizeBeats([[
        ...$original[0],
        'title' => '教授考察并指导林墨',
    ]]);

    expect($original[0]['key'])->toBe('academy-professor-training')
        ->and($renamed[0]['key'])->toBe('academy-professor-training')
        ->and($renamed[0]['sequence'])->toBe(2)
        ->and($renamed[0]['chapter_budget'])->toBe(['min' => 2, 'max' => 4])
        ->and($renamed[0]['character_candidates'])->toBe([['candidate_key' => 'character-professor']]);
});

test('story arc beat contracts read legacy and structured beats together', function () {
    $contract = new StoryArcBeatContract;
    $arc = new StoryArc([
        'beats' => [
            '旧节点',
            [
                'key' => 'stable-new-beat',
                'sequence' => 5,
                'title' => '新结构节点',
            ],
        ],
    ]);

    expect($contract->forArc($arc))->toBe([
        [
            'beat_key' => $contract->key('旧节点'),
            'beat_index' => 1,
            'text' => '旧节点',
        ],
        [
            'beat_key' => 'stable-new-beat',
            'beat_index' => 5,
            'text' => '新结构节点',
        ],
    ]);
});
