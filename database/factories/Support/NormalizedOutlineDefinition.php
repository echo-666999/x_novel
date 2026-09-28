<?php

namespace Database\Factories\Support;

use App\Enums\StoryArcType;

/**
 * 为跨领域 Factory 提供同一份最小关系化 Outline。
 *
 * 该定义只服务测试夹具，避免 Volume、Story Arc 与 Chapter Plan 分别创建互不相干的来源版本。
 */
final class NormalizedOutlineDefinition
{
    /**
     * 返回包含一个主线 Milestone 的完整定义，可直接交给关系化 Outline 创建 Action。
     *
     * @return array<string, mixed>
     */
    public static function make(): array
    {
        return [
            'title' => 'Factory Outline',
            'summary' => '为运行态测试记录提供完整且可追踪的主线来源。',
            'must_include' => [],
            'must_not_include' => [],
            'volumes' => [[
                'key' => 'factory-volume',
                'sequence' => 1,
                'title' => 'Factory Volume',
                'goal' => '提供测试目标。',
                'climax' => '完成测试目标。',
                'target_words' => 10_000,
                'arcs' => [[
                    'key' => 'factory-arc',
                    'sequence' => 1,
                    'mainline_sequence' => 1,
                    'type' => StoryArcType::Main->value,
                    'title' => 'Factory Arc',
                    'goal' => '验证运行态记录的来源父链。',
                    'stakes' => '引用错误会阻止保存。',
                    'completion_conditions' => ['测试目标完成。'],
                    'beats' => [[
                        'key' => 'factory-beat',
                        'sequence' => 1,
                        'mainline_sequence' => 1,
                        'title' => 'Factory Beat',
                        'summary' => '完成一项测试推进。',
                        'chapter_budget' => ['min' => 1, 'max' => 2],
                        'acceptance_criteria' => ['计划引用已保存。'],
                        'must_include' => [],
                        'must_not_include' => [],
                        'character_candidates' => [],
                        'world_entity_candidates' => [],
                        'milestones' => [[
                            'key' => 'factory-milestone',
                            'sequence' => 1,
                            'title' => 'Factory Milestone',
                            'objective' => '保存有效章节计划。',
                            'acceptance_criteria' => ['完整父链存在。'],
                            'must_include' => [],
                            'must_not_include' => [],
                        ]],
                        'handoff' => [
                            'next_beat_key' => null,
                            'transition_mode' => null,
                            'exit_result' => null,
                            'next_trigger' => null,
                            'carried_states' => [],
                            'open_threads' => [],
                            'required_transition' => [],
                            'forbidden_jump' => [],
                        ],
                    ]],
                ]],
            ]],
        ];
    }
}
