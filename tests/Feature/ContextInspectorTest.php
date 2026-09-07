<?php

use App\Enums\MemoryStatus;
use App\Models\GenerationRun;
use App\Models\Memory;
use App\Models\Novel;
use App\Services\ContextInspector;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('context inspector normalizes all layers tokens truncation and selected memory sources', function () {
    $novel = Novel::factory()->create();
    $memory = Memory::factory()->for($novel)->create([
        'summary' => '主角曾在旧都取得王室密钥。',
        'valid_from_chapter' => 8,
        'status' => MemoryStatus::Invalid,
    ]);
    $run = GenerationRun::factory()->for($novel)->create([
        'context_snapshot' => [
            'state_version' => 5,
            'bible_version' => 2,
            'prompt_version' => 'scene-writer-v1',
            'model' => 'writer-model',
            'l0' => ['rules' => ['禁止复活']],
            'l1' => ['state' => ['location' => '旧都']],
            'l2' => ['recent_chapters' => []],
            'l3' => ['memories' => [[
                'id' => $memory->getKey(),
                'summary' => '主角曾在旧都取得王室密钥。',
                'final_score' => 0.91,
            ]]],
            'l4' => ['style' => '克制'],
            'memory_ids' => [$memory->getKey()],
            'token_allocation' => [
                'budget' => 4000,
                'used' => 1300,
                'remaining' => 2700,
                'sections' => ['l0' => 300, 'l1' => 500, 'l2' => 400, 'l3' => 100],
            ],
            'truncated_sections' => ['l3.long_term_memory'],
        ],
    ]);

    $inspection = app(ContextInspector::class)->inspect($run);

    expect($inspection['l0']['rules'])->toBe(['禁止复活'])
        ->and($inspection['l4']['style'])->toBe('克制')
        ->and($inspection['token_allocation']['sections'])->toContain(['section' => 'L3', 'tokens' => 100])
        ->and($inspection['truncated_sections'])->toBe([['section' => 'l3.long_term_memory']])
        ->and($inspection['selected_memories'][0]['summary'])->toBe('主角曾在旧都取得王室密钥。')
        ->and($inspection['selected_memories'][0]['source'])->toBe($memory->sourceLabel())
        ->and($inspection['selected_memories'][0]['status'])->toBe('已失效');
});

test('context inspector never exposes a selected memory from another novel', function () {
    $novel = Novel::factory()->create();
    $foreign = Memory::factory()->create(['summary' => '另一本小说的秘密内容']);
    $run = GenerationRun::factory()->for($novel)->create([
        'context_snapshot' => [
            'memory_ids' => [$foreign->getKey()],
            'l3' => ['memories' => [[
                'id' => $foreign->getKey(),
                'summary' => '另一本小说的秘密内容',
                'source' => '故事事件 #999',
            ]]],
        ],
    ]);

    $inspection = app(ContextInspector::class)->inspect($run);

    expect($inspection['selected_memories'][0]['summary'])->toContain('当前 Memory 记录不可用')
        ->and($inspection['selected_memories'][0]['summary'])->not->toContain('秘密内容')
        ->and($inspection['selected_memories'][0]['source'])->toBe('—');
});

test('context inspector tolerates legacy snapshots with missing layers', function () {
    $run = GenerationRun::factory()->create(['context_snapshot' => ['state_version' => 1]]);

    $inspection = app(ContextInspector::class)->inspect($run);

    expect($inspection['l0'])->toBe([])
        ->and($inspection['l3'])->toBe([])
        ->and($inspection['l4'])->toBe([])
        ->and($inspection['selected_memories'])->toBe([])
        ->and($inspection['token_allocation']['sections'])->toBe([]);
});
