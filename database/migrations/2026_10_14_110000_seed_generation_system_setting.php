<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 这是新安装环境的一次性初始数据；已由 UI 保存的配置绝不能被部署迁移覆盖。
        DB::table('system_settings')->insertOrIgnore([
            'key' => 'generation',
            'value' => json_encode($this->initialSettings(), JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // 回滚代码时保留用户在 UI 中维护的生成参数，避免删除已经生效的运营配置。
    }

    /** @return array<string, mixed> */
    private function initialSettings(): array
    {
        return [
            'schema_version' => 1,
            'currency' => 'USD',
            'budget' => [
                'daily_hard_limit' => null,
                'novel_total_limit' => null,
                'chapter_max_cost' => null,
            ],
            'review_context_token_budget' => 48_000,
            'embedding_dimensions' => 1_536,
            'outline_request_budgets' => [
                'outline_foundation' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
                'outline_structure' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 4_000],
                'outline_arc_beats' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 4_000],
                'outline_beat_detail' => ['output_tokens' => 5_000, 'reasoning_reserve_tokens' => 3_000],
            ],
            'chapter_request_budgets' => [
                'planner' => [
                    'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 4_000],
                    'retry' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
                    'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 16_000],
                ],
                'writer' => [
                    'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
                    'retry' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 16_000],
                    'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 24_000],
                ],
                'extractor' => [
                    'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 32_000],
                    'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 40_000],
                    'final' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 48_000],
                ],
                'reviewer' => [
                    'initial' => ['output_tokens' => 12_000, 'reasoning_reserve_tokens' => 8_000],
                ],
                'rewrite' => [
                    'initial' => ['output_tokens' => 16_000, 'reasoning_reserve_tokens' => 8_000],
                    'retry' => ['output_tokens' => 20_000, 'reasoning_reserve_tokens' => 16_000],
                    'final' => ['output_tokens' => 24_000, 'reasoning_reserve_tokens' => 24_000],
                ],
                'summary' => [
                    'initial' => ['output_tokens' => 1_200, 'reasoning_reserve_tokens' => 8_000],
                    'retry' => ['output_tokens' => 2_400, 'reasoning_reserve_tokens' => 16_000],
                    'final' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 24_000],
                ],
            ],
            'chapter_repair_request_budgets' => [
                'extractor' => [
                    'scene_structure' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                    'coverage_evidence' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 16_000],
                        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 24_000],
                    ],
                    'foreshadowing_coverage' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                    'event_evidence' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                ],
                'reviewer' => [
                    'coverage_judgment' => [
                        'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 3_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                    'review_schema' => [
                        'initial' => ['output_tokens' => 1_500, 'reasoning_reserve_tokens' => 8_000],
                    ],
                    'arc_completion' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                    ],
                ],
                'rewrite' => [
                    'coverage_evidence' => [
                        'initial' => ['output_tokens' => 1_000, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 2_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                    'length_repair' => [
                        'initial' => ['output_tokens' => 4_000, 'reasoning_reserve_tokens' => 8_000],
                        'retry' => ['output_tokens' => 8_000, 'reasoning_reserve_tokens' => 16_000],
                    ],
                ],
            ],
        ];
    }
};
