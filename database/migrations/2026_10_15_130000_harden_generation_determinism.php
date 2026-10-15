<?php

use App\Enums\ArtifactValidationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 把主线唯一性、Run 租约、费用预留和 Artifact 来源身份下沉到 PostgreSQL。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->timestamp('queued_at')->nullable()->comment('进入队列时间');
            $table->timestamp('lease_expires_at')->nullable()->comment('排队或运行租约过期时间');
            $table->uuid('lease_token')->nullable()->comment('本次队列租约唯一标识');
            $table->timestamp('heartbeat_at')->nullable()->comment('Worker 最近心跳时间');
            $table->decimal('reserved_cost', 18, 6)->default(0)->comment('尚未结算的最大请求费用预留');
            $table->index(['status', 'lease_expires_at'], 'generation_runs_lease_expiry_index');
        });

        Schema::table('generation_artifacts', function (Blueprint $table): void {
            $table->char('input_hash', 64)->nullable()->comment('产生该产物的语义输入哈希');
            $table->foreignId('source_artifact_id')->nullable()->comment('直接上游产物')->constrained('generation_artifacts')->nullOnDelete();
            $table->string('validation_status')->default(ArtifactValidationStatus::Validated->value)->comment('确定性校验状态');
            $table->index(['type', 'input_hash', 'validation_status'], 'generation_artifacts_identity_index');
            $table->index(['source_artifact_id', 'validation_status'], 'generation_artifacts_source_index');
        });

        DB::table('generation_runs')
            ->where('status', 'queued')
            ->whereNull('queued_at')
            ->update(['queued_at' => DB::raw('created_at')]);

        DB::table('generation_artifacts')->orderBy('id')->each(function (object $artifact): void {
            $run = DB::table('generation_runs')->where('id', $artifact->generation_run_id)->first(['input_hash']);
            $data = is_string($artifact->data) ? json_decode($artifact->data, true) : $artifact->data;
            $sourceId = is_array($data) && is_numeric($data['source_artifact_id'] ?? null)
                ? (int) $data['source_artifact_id']
                : null;

            DB::table('generation_artifacts')->where('id', $artifact->id)->update([
                'input_hash' => $run?->input_hash,
                'source_artifact_id' => $sourceId,
            ]);
        });

        // 旧数据若已出现多个活动主线，确定性保留顺序最早的一条，再建立数据库唯一约束。
        DB::table('story_arcs')
            ->where('type', 'main')
            ->where('status', 'active')
            ->orderBy('novel_id')
            ->orderBy('sequence')
            ->orderBy('id')
            ->get(['id', 'novel_id'])
            ->groupBy('novel_id')
            ->each(function ($arcs): void {
                $ids = $arcs->pluck('id')->slice(1)->all();
                if ($ids !== []) {
                    DB::table('story_arcs')->whereIn('id', $ids)->update(['status' => 'planned']);
                }
            });

        $connection = DB::connection();
        $grammar = $connection->getSchemaGrammar();
        $arcsTable = $grammar->wrapTable('story_arcs');
        $artifactsTable = $grammar->wrapTable('generation_artifacts');
        $runsTable = $grammar->wrapTable('generation_runs');
        DB::statement("CREATE UNIQUE INDEX story_arcs_one_active_main_per_novel ON {$arcsTable} (novel_id) WHERE type = 'main' AND status = 'active'");

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE {$artifactsTable} ADD CONSTRAINT generation_artifacts_validation_status_check CHECK (validation_status IN ('pending', 'validated', 'rejected'))");
            DB::statement("ALTER TABLE {$runsTable} ADD CONSTRAINT generation_runs_reserved_cost_check CHECK (reserved_cost >= 0)");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS story_arcs_one_active_main_per_novel');

        if (DB::getDriverName() === 'pgsql') {
            $grammar = DB::connection()->getSchemaGrammar();
            $artifactsTable = $grammar->wrapTable('generation_artifacts');
            $runsTable = $grammar->wrapTable('generation_runs');
            DB::statement("ALTER TABLE {$artifactsTable} DROP CONSTRAINT IF EXISTS generation_artifacts_validation_status_check");
            DB::statement("ALTER TABLE {$runsTable} DROP CONSTRAINT IF EXISTS generation_runs_reserved_cost_check");
        }

        Schema::table('generation_artifacts', function (Blueprint $table): void {
            $table->dropForeign(['source_artifact_id']);
            $table->dropIndex('generation_artifacts_identity_index');
            $table->dropIndex('generation_artifacts_source_index');
            $table->dropColumn(['input_hash', 'source_artifact_id', 'validation_status']);
        });

        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropIndex('generation_runs_lease_expiry_index');
            $table->dropColumn(['queued_at', 'lease_expires_at', 'lease_token', 'heartbeat_at', 'reserved_cost']);
        });
    }
};
