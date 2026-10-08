<?php

use App\Enums\GenerationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 为 Admission v1 的零 Provider 恢复合同扩展 PostgreSQL 阶段约束。 */
return new class extends Migration
{
    /** 将章节恢复阶段加入数据库允许值，保证恢复合同可以独立审计。 */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceStageCheck(GenerationStage::cases());
    }

    /** 回滚时恢复为不包含章节恢复阶段的旧阶段集合。 */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $stages = array_values(array_filter(
            GenerationStage::cases(),
            fn (GenerationStage $stage): bool => $stage !== GenerationStage::ChapterRecovery,
        ));
        $this->replaceStageCheck($stages);
    }

    /**
     * 原子替换阶段 CHECK，避免 PHP 枚举已经可用而 PostgreSQL 仍拒绝写入。
     *
     * @param  array<int, GenerationStage>  $stages
     */
    private function replaceStageCheck(array $stages): void
    {
        $connection = DB::connection();
        $table = $connection->getSchemaGrammar()->wrapTable('generation_runs');
        $quoted = collect($stages)
            ->map(fn (GenerationStage $stage): string => $connection->getPdo()->quote($stage->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS generation_runs_stage_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT generation_runs_stage_check CHECK (stage IN ({$quoted}))");
    }
};
