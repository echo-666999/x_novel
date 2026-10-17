<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** R01 只增加新链所需基础；旧层级在 R07 核实无引用后删除，不迁移旧小说。 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_settings', function (Blueprint $table): void {
            $table->jsonb('policy_history')->default('[]')->comment('金额策略修改历史，记录操作人、币种与修改前后值');
        });
        Schema::create('novel_generation_tasks', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->uuid('submission_key')->comment('创建请求幂等标识');
            $table->string('status')->default('queued')->comment('queued排队/running运行/paused暂停/needs_attention待处理/completed完成/cancelled取消/failed终止');
            $table->string('mode')->default('full_book')->comment('full_book整书/opening开篇/manual_review逐章提交');
            $table->unsignedBigInteger('intent_artifact_id')->comment('原始意图不可变产物');
            $table->bigInteger('target_words')->comment('目标正文字数，非空白Unicode码点');
            $table->integer('opening_chapter_count')->default(3)->comment('开篇模式正式章节目标');
            $table->jsonb('policy_snapshot')->default('{}')->comment('版本化授权与运行策略，不包含密钥');
            $table->integer('policy_revision')->default(1)->comment('策略修订版本');
            $table->integer('control_version')->default(1)->comment('暂停恢复的并发控制版本');
            $table->string('current_phase')->default('intake')->comment('当前粗阶段，非正式正文指针');
            $table->unsignedBigInteger('current_chapter_id')->nullable()->comment('当前章节');
            $table->jsonb('pending_transition')->nullable()->comment('待派发阶段及幂等身份');
            $table->jsonb('progress_snapshot')->default('{}')->comment('可重算进度快照');
            $table->timestampTz('last_progress_at')->nullable()->comment('最后有效业务进展时间');
            $table->string('stop_reason')->nullable()->comment('暂停或终止原因');
            $table->jsonb('stop_detail')->nullable()->comment('结构化停止说明');
            $table->unsignedBigInteger('replaces_task_id')->nullable()->comment('替代的同小说任务');
            $table->unsignedBigInteger('created_by')->nullable()->comment('操作账号');
            $table->unsignedBigInteger('export_manifest_id')->nullable()->comment('完整正式作品导出清单');
            $table->timestampTz('started_at')->nullable()->comment('开始时间');
            $table->timestampTz('finished_at')->nullable()->comment('结束时间');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->comment('整书生成任务：保存长期意图与控制状态，不持Worker租约');
        });
        Schema::create('generation_issues', function (Blueprint $table): void {
            $table->id()->comment('主键');
            $table->unsignedBigInteger('novel_id')->comment('所属小说');
            $table->unsignedBigInteger('generation_task_id')->comment('所属整书任务');
            $table->unsignedBigInteger('chapter_id')->nullable()->comment('受影响章节');
            $table->string('fingerprint')->comment('问题规则范围与来源指纹');
            $table->jsonb('source_identity')->default('{}')->comment('来源ID版本与哈希');
            $table->string('category')->comment('问题分类');
            $table->string('error_code')->comment('稳定错误码');
            $table->string('severity')->default('error')->comment('info/warning/error/critical严重性');
            $table->string('status')->default('open')->comment('open/diagnosing/repairing/awaiting_user/resolved生命周期');
            $table->unsignedBigInteger('first_run_id')->nullable()->comment('首次失败执行');
            $table->unsignedBigInteger('latest_run_id')->nullable()->comment('最近处理执行');
            $table->unsignedBigInteger('resolved_by_run_id')->nullable()->comment('复验解决执行');
            $table->text('user_message')->comment('面向用户的中文原因');
            $table->jsonb('evidence')->default('{}')->comment('脱敏证据与正文定位');
            $table->jsonb('allowed_actions')->default('[]')->comment('服务端白名单处理选项');
            $table->integer('attempt_count')->default(0)->comment('累计修复尝试次数');
            $table->bigInteger('repair_elapsed_ms')->default(0)->comment('累计实际修复执行毫秒');
            $table->jsonb('repair_limit_snapshot')->default('{}')->comment('问题修复限制快照');
            $table->timestampTz('deadline_at')->nullable()->comment('独立总截止时间，默认不设');
            $table->jsonb('verification')->nullable()->comment('解决时复验依据');
            $table->text('resolution_reason')->nullable()->comment('解决或软例外原因');
            $table->timestampTz('first_seen_at')->nullable()->comment('首次观测时间');
            $table->timestampTz('last_seen_at')->nullable()->comment('最近观测时间');
            $table->timestampTz('resolved_at')->nullable()->comment('复验解决时间');
            $table->timestampTz('created_at')->nullable()->comment('创建时间');
            $table->timestampTz('updated_at')->nullable()->comment('更新时间');
            $table->comment('生成问题：跨尝试保存异常、人工选项与复验依据');
        });
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->unsignedBigInteger('generation_task_id')->nullable()->comment('所属整书任务，初始化事务内允许暂空');
            $table->unsignedBigInteger('issue_id')->nullable()->comment('关联问题');
            $table->unsignedBigInteger('retry_of_run_id')->nullable()->comment('前一次尝试');
            $table->string('logical_step_key')->nullable()->comment('小说范围阶段及输入身份');
            $table->string('pipeline_version')->default('legacy')->comment('流程合同版本，旧入口待R07退出');
            $table->string('schema_version')->default('1')->comment('输出Schema版本');
            $table->string('model')->nullable()->comment('冻结实际模型');
            $table->unsignedBigInteger('connection_id')->nullable()->comment('冻结连接引用，不包含密钥');
            $table->string('scope_key')->nullable()->comment('书章或片段逻辑范围');
            $table->jsonb('source_manifest')->default('{}')->comment('不可变来源清单和哈希');
            $table->jsonb('execution_contract')->default('{}')->comment('冻结Prompt路由参数与容量合同');
            $table->unsignedBigInteger('result_artifact_id')->nullable()->comment('本次有效输出');
            $table->timestampTz('next_attempt_at')->nullable()->comment('退避结束时间');
            $table->timestampTz('dispatch_expires_at')->nullable()->comment('允许再次补派的时间');
            $table->jsonb('checkpoint')->nullable()->comment('真正未完成工作的持久断点');
            $table->string('outcome_code')->nullable()->comment('业务结论，与技术错误分离');
            $table->comment('小说生成系统：generation_runs，R01来源与执行基础');
        });
        Schema::table('generation_artifacts', function (Blueprint $table): void {
            $table->unsignedBigInteger('novel_id')->nullable()->comment('所属小说，与Run复合关联');
            $table->string('schema_version')->default('1')->comment('产物Schema版本');
            $table->jsonb('source_manifest')->default('{}')->comment('所有直接来源及哈希');
            $table->comment('小说生成系统：generation_artifacts，R01来源与执行基础');
        });
        Schema::table('novels', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_generation_task_id')->nullable()->comment('当前未结束整书任务');
            $table->unsignedBigInteger('current_blueprint_id')->nullable()->comment('当前全书蓝图版本');
            $table->unsignedBigInteger('current_goal_window_id')->nullable()->comment('当前近期目标版本');
            $table->comment('小说生成系统：novels，R01来源与执行基础');
        });
        Schema::table('novel_outlines', function (Blueprint $table): void {
            $table->string('kind')->default('blueprint')->comment('blueprint全书蓝图/goal_window近期目标');
            $table->comment('小说生成系统：novel_outlines，R01来源与执行基础');
        });
        Schema::table('chapter_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('novel_id')->nullable()->comment('所属小说，与章节复合关联');
            $table->unsignedBigInteger('blueprint_id')->nullable()->comment('蓝图版本');
            $table->unsignedBigInteger('goal_window_id')->nullable()->comment('近期目标版本');
            $table->jsonb('goal_refs')->default('[]')->comment('本章实际依赖的目标key与hash');
            $table->unsignedBigInteger('source_state_version_id')->nullable()->comment('正式前置状态版本');
            $table->string('schema_version')->default('1')->comment('计划Schema版本');
            $table->comment('小说生成系统：chapter_plans，R01来源与执行基础');
        });
        Schema::table('chapters', function (Blueprint $table): void {
            $table->unsignedBigInteger('current_plan_id')->nullable()->comment('当前冻结计划');
            $table->unsignedBigInteger('current_draft_artifact_id')->nullable()->comment('当前完整草稿');
            $table->unsignedBigInteger('summary_artifact_id')->nullable()->comment('正式摘要来源');
            $table->comment('小说生成系统：chapters，R01来源与执行基础');
        });
        Schema::table('reviews', function (Blueprint $table): void {
            $table->unsignedBigInteger('novel_id')->nullable()->comment('所属小说');
            $table->jsonb('source_manifest')->default('{}')->comment('同源正文计划事件及状态');
            $table->string('rules_version')->default('1')->comment('审校规则版本');
            $table->comment('小说生成系统：reviews，R01来源与执行基础');
        });
        Schema::table('ai_provider_connections', function (Blueprint $table): void {
            $table->text('api_key')->nullable()->change()->comment('已停用的旧密文字段，禁止新写入，R07清理');
            $table->string('credential_ref')->nullable()->comment('config白名单凭据引用，禁止保存密钥');
            $table->comment('小说生成系统：ai_provider_connections，R01来源与执行基础');
        });
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable()->comment('发送前生成的请求幂等身份');
            $table->unsignedBigInteger('generation_task_id')->nullable()->comment('费用归属整书任务');
            $table->unsignedBigInteger('issue_id')->nullable()->comment('费用归属问题');
            $table->string('status')->default('settled')->comment('prepared待发/in_flight在途/settled已记/unknown未知/not_sent确认未发');
            $table->string('cost_status')->default('estimated')->comment('unknown未知/estimated估计/actual实际');
            $table->string('currency')->default('USD')->comment('计价币种');
            $table->jsonb('price_snapshot')->default('{}')->comment('当次价格版本与依据');
            $table->decimal('actual_cost', 18, 8)->nullable()->comment('确认结算金额');
            $table->decimal('reserved_amount', 18, 8)->nullable()->comment('用户启用硬上限时保守占用金额');
            $table->timestampTz('sent_at')->nullable()->comment('实际发送时间');
            $table->timestampTz('settled_at')->nullable()->comment('响应记账时间');
            $table->timestampTz('reconciled_at')->nullable()->comment('最后对账时间');
            $table->jsonb('cap_snapshot')->default('{}')->comment('本次主动启用的限制与授权版本');
            $table->jsonb('reconciliation_history')->default('[]')->comment('核销修改的前后值与依据');
            $table->comment('小说生成系统：usage_records，R01来源与执行基础');
        });
        // 章节/片段不依赖旧分卷、Arc、Scene；旧字段保留至清理批次但允许为空。
        Schema::table('chapter_plans', function (Blueprint $table): void {
            $table->text('arc_contribution')->nullable()->change()->comment('旧故事线贡献，非新版必填');
            $table->jsonb('scene_plans')->nullable()->change()->comment('可选片段计划，整章写作可为空');
        });
        Schema::table('usage_records', function (Blueprint $table): void {
            foreach (['input_tokens', 'output_tokens', 'cached_tokens', 'reasoning_tokens'] as $column) {
                $table->bigInteger($column)->nullable()->default(null)->change()->comment('实际Token数量，未知为null');
            }
            $table->integer('latency_ms')->nullable()->default(null)->change()->comment('实际请求毫秒，未知为null');
            $table->decimal('estimated_cost', 18, 8)->nullable()->default(null)->change()->comment('估计费用，缺价格时为null而非零');
            $table->unique('request_key');
            $table->unique('generation_run_id', 'usage_one_request_per_run');
        });
        Schema::table('novel_generation_tasks', function (Blueprint $table): void {
            $table->unique('submission_key');
            $table->foreign('novel_id')->references('id')->on('novels')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        // 复合外键在数据库层拒绝跨小说引用，不能只依赖表单校验。
        foreach (['novel_generation_tasks', 'generation_issues', 'generation_runs', 'generation_artifacts', 'chapter_plans', 'chapters', 'novel_outlines', 'reviews', 'story_state_versions'] as $tableName) {
            Schema::table($tableName, fn (Blueprint $table) => $table->unique(['novel_id', 'id'], $tableName.'_novel_identity'));
        }
        $references = [
            ['novel_generation_tasks', 'intent_artifact_id', 'generation_artifacts'],
            ['novel_generation_tasks', 'current_chapter_id', 'chapters'],
            ['novel_generation_tasks', 'replaces_task_id', 'novel_generation_tasks'],
            ['novel_generation_tasks', 'export_manifest_id', 'generation_artifacts'],
            ['generation_runs', 'generation_task_id', 'novel_generation_tasks'],
            ['generation_runs', 'issue_id', 'generation_issues'],
            ['generation_runs', 'chapter_id', 'chapters'],
            ['generation_runs', 'retry_of_run_id', 'generation_runs'],
            ['generation_runs', 'result_artifact_id', 'generation_artifacts'],
            ['generation_artifacts', 'generation_run_id', 'generation_runs'],
            ['generation_artifacts', 'source_artifact_id', 'generation_artifacts'],
            ['generation_issues', 'generation_task_id', 'novel_generation_tasks'],
            ['generation_issues', 'chapter_id', 'chapters'],
            ['generation_issues', 'first_run_id', 'generation_runs'],
            ['generation_issues', 'latest_run_id', 'generation_runs'],
            ['generation_issues', 'resolved_by_run_id', 'generation_runs'],
            ['chapter_plans', 'chapter_id', 'chapters'],
            ['chapter_plans', 'blueprint_id', 'novel_outlines'],
            ['chapter_plans', 'goal_window_id', 'novel_outlines'],
            ['chapter_plans', 'source_state_version_id', 'story_state_versions'],
            ['chapters', 'current_plan_id', 'chapter_plans'],
            ['chapters', 'current_draft_artifact_id', 'generation_artifacts'],
            ['chapters', 'summary_artifact_id', 'generation_artifacts'],
            ['reviews', 'generation_run_id', 'generation_runs'],
            ['reviews', 'artifact_id', 'generation_artifacts'],
            ['novel_outlines', 'source_artifact_id', 'generation_artifacts'],
            ['usage_records', 'generation_task_id', 'novel_generation_tasks'],
            ['usage_records', 'issue_id', 'generation_issues'],
            ['usage_records', 'generation_run_id', 'generation_runs'],
        ];
        foreach ($references as $index => [$tableName,$column,$target]) {
            Schema::table($tableName, fn (Blueprint $table) => $table->foreign(['novel_id', $column], 'r01_source_'.$index)->references(['novel_id', 'id'])->on($target)->restrictOnDelete());
        }
        foreach (['current_generation_task_id' => 'novel_generation_tasks', 'current_blueprint_id' => 'novel_outlines', 'current_goal_window_id' => 'novel_outlines'] as $column => $target) {
            Schema::table('novels', fn (Blueprint $table) => $table->foreign(['id', $column], 'r01_'.$column)->references(['novel_id', 'id'])->on($target)->restrictOnDelete());
        }
        Schema::table('novel_outlines', function (Blueprint $table): void {
            $table->dropUnique(['novel_id', 'version']);
            $table->unique(['novel_id', 'kind', 'version']);
        });
        $wrap = fn (string $name): string => DB::connection()->getSchemaGrammar()->wrapTable($name);
        DB::statement('DROP INDEX IF EXISTS novel_outlines_current_unique');
        DB::statement('CREATE UNIQUE INDEX novel_outlines_current_unique ON '.$wrap('novel_outlines')." (novel_id,kind) WHERE status = 'current'");
        DB::statement('CREATE UNIQUE INDEX r01_one_main_task ON '.$wrap('novel_generation_tasks')." (novel_id) WHERE status IN ('queued','running','paused','needs_attention')");
        DB::statement('CREATE UNIQUE INDEX r01_active_issue ON '.$wrap('generation_issues')." (novel_id,fingerprint) WHERE status <> 'resolved'");
        DB::statement('CREATE UNIQUE INDEX r01_active_step ON '.$wrap('generation_runs')." (logical_step_key) WHERE logical_step_key IS NOT NULL AND status IN ('queued','running')");
        Schema::table('generation_runs', fn (Blueprint $table) => $table->unique(['logical_step_key', 'attempt']));
        if (DB::getDriverName() === 'pgsql') {
            foreach (['generation_runs' => ['stage' => GenerationStage::cases(), 'status' => RunStatus::cases()], 'generation_artifacts' => ['type' => ArtifactType::cases()]] as $name => $columns) {
                foreach ($columns as $column => $cases) {
                    DB::statement('ALTER TABLE '.$wrap($name).' DROP CONSTRAINT IF EXISTS '.$name.'_'.$column.'_check');
                    $values = implode(',', array_map(fn ($case) => DB::connection()->getPdo()->quote($case->value), $cases));
                    DB::statement('ALTER TABLE '.$wrap($name).' ADD CONSTRAINT '.$name.'_'.$column.'_check CHECK ('.$column.' IN ('.$values.'))');
                }
            }
            $checks = [
                'novel_generation_tasks' => ["status IN ('queued','running','paused','needs_attention','completed','cancelled','failed')", "mode IN ('full_book','opening','manual_review')", 'target_words > 0 AND opening_chapter_count > 0 AND control_version > 0 AND policy_revision > 0'],
                'generation_issues' => ["status IN ('open','diagnosing','repairing','awaiting_user','resolved')", 'attempt_count >= 0 AND repair_elapsed_ms >= 0'],
                'generation_runs' => ["pipeline_version <> 'pipeline-v1' OR (logical_step_key IS NOT NULL AND scope_key IS NOT NULL)"],
                'novel_outlines' => ["kind IN ('blueprint','goal_window')"],
                'usage_records' => ["status IN ('prepared','in_flight','settled','unknown','not_sent')", "cost_status IN ('unknown','estimated','actual')", 'reserved_amount IS NULL OR reserved_amount >= 0', 'actual_cost IS NULL OR actual_cost >= 0'],
            ];
            foreach ($checks as $name => $rules) {
                foreach ($rules as $index => $rule) {
                    DB::statement('ALTER TABLE '.$wrap($name).' ADD CONSTRAINT r01_'.$name.'_'.$index.' CHECK ('.$rule.')');
                }
            }
            foreach (['generation_runs' => 'context_snapshot', 'generation_artifacts' => 'data', 'reviews' => 'findings'] as $name => $column) {
                DB::statement('ALTER TABLE '.$wrap($name).' ALTER COLUMN '.$column.' TYPE jsonb USING '.$column.'::jsonb');
                DB::statement('COMMENT ON COLUMN '.$wrap($name).'.'.$column." IS '版本化结构快照，采用JSONB保存'");
            }
        }
    }

    /** 新链历史不可逆删；仅在隔离库通过 migrate:fresh 重建，不提供危险的生产降级。 */
    public function down(): void
    {
        throw new LogicException('R01 Schema 不支持原地降级；仅在核实隔离数据库后重建空库。');
    }
};
