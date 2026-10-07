<?php

use App\Models\ChapterPlan;
use Illuminate\Support\Facades\DB;

test('chapter plan checksum survives a real postgres jsonb round trip', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('真实 jsonb 往返验证需要 PostgreSQL。');
    }

    $scenePlans = [[
        'turn' => '发现潮声来自墙内',
        'goal' => '确认密室入口',
        'outcome' => '锁定暗门位置',
        'conflict' => '守卫正在接近',
        'outcome_allowed' => ['先确认水声', '再停止喂乳'],
        'outcome_forbidden' => ['提前揭露幕后人物', '跳过现场验证'],
        'continuity_requirements' => ['保留湿脚印', '携带铜钥匙'],
        'transition_from_previous' => null,
    ]];
    $arcContributions = [[
        'target_scene_sequence' => 1,
        'milestone_sequence' => 2,
        'milestone_key' => 'milestone-02',
        'beat_index' => 1,
        'beat_key' => 'beat-01',
        'arc_id' => 3,
        'role' => 'primary',
    ]];
    $payload = [
        'novel_outline_id' => 11,
        'primary_outline_arc_id' => 3,
        'primary_outline_beat_id' => 7,
        'primary_outline_milestone_id' => 13,
        'chapter_function' => '推动主线调查。',
        'arc_contribution' => '确认第一处异常。',
        'arc_contributions' => $arcContributions,
        'character_candidates' => [],
        'reader_promise' => '揭示密室线索。',
        'target_words' => 3_000,
        'pov_character_id' => 5,
        'tone' => '紧张',
        'time_anchor' => '当日黄昏',
        'hook_type' => '悬念',
        'must_reveal' => ['墙后存在空腔'],
        'may_hint' => ['守卫知情'],
        'must_not_reveal' => ['幕后人物身份'],
        'required_facts' => [['value' => '铜钥匙仍由主角持有', 'key' => 'key-owner']],
        'forbidden_conflicts' => [],
        'foreshadowing_actions' => [],
        'world_entity_candidates' => [],
        'scene_plans' => $scenePlans,
    ];
    $checksumBeforeSave = (new ChapterPlan($payload))->semanticChecksum();

    // 使用临时表执行真实 PostgreSQL jsonb 编码与解码，避免测试触碰开发库中的业务记录。
    DB::transaction(function () use ($arcContributions, $checksumBeforeSave, $payload, $scenePlans): void {
        $chapterPlansTable = DB::connection()->getTablePrefix().(new ChapterPlan)->getTable();
        $actualColumnType = DB::scalar(<<<'SQL'
            SELECT data_type
            FROM information_schema.columns
            WHERE table_schema = current_schema()
              AND table_name = ?
              AND column_name = 'scene_plans'
            SQL, [$chapterPlansTable]);

        expect($actualColumnType)->toBe('jsonb');

        DB::statement(<<<'SQL'
            CREATE TEMPORARY TABLE chapter_plan_checksum_jsonb_round_trip (
                scene_plans jsonb NOT NULL,
                arc_contributions jsonb NOT NULL
            ) ON COMMIT DROP
            SQL);

        $row = DB::selectOne(<<<'SQL'
            INSERT INTO chapter_plan_checksum_jsonb_round_trip (scene_plans, arc_contributions)
            VALUES (CAST(? AS jsonb), CAST(? AS jsonb))
            RETURNING
                scene_plans::text AS scene_plans,
                arc_contributions::text AS arc_contributions,
                pg_typeof(scene_plans)::text AS scene_plans_type
            SQL, [
            json_encode($scenePlans, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            json_encode($arcContributions, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        $roundTrippedPayload = [
            ...$payload,
            'scene_plans' => json_decode($row->scene_plans, true, 512, JSON_THROW_ON_ERROR),
            'arc_contributions' => json_decode($row->arc_contributions, true, 512, JSON_THROW_ON_ERROR),
        ];
        $checksumAfterSave = (new ChapterPlan($roundTrippedPayload))->semanticChecksum();

        expect($row->scene_plans_type)->toBe('jsonb')
            ->and(data_get($roundTrippedPayload, 'scene_plans.0.outcome_allowed'))
            ->toBe(['先确认水声', '再停止喂乳'])
            ->and($checksumAfterSave)->toBe($checksumBeforeSave);
    });
});
