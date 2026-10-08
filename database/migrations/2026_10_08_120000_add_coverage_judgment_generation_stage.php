<?php

use App\Enums\GenerationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 为独立 Coverage Judgment Run 扩展 PostgreSQL 阶段约束。 */
return new class extends Migration
{
    /** 将当前枚举中的 Coverage Judgment 纳入数据库合法阶段。 */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceStageCheck(GenerationStage::cases());
    }

    /** 回滚时恢复为不包含 Coverage Judgment 的旧阶段集合。 */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $stages = array_values(array_filter(
            GenerationStage::cases(),
            fn (GenerationStage $stage): bool => $stage !== GenerationStage::CoverageJudgment,
        ));
        $this->replaceStageCheck($stages);
    }

    /**
     * 原子替换阶段 CHECK，确保应用枚举与 PostgreSQL 约束保持一致。
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
