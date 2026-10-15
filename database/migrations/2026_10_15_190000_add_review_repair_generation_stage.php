<?php

use App\Enums\GenerationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** 为独立审校合同修复 Run 扩展 PostgreSQL 阶段约束。 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replaceStageCheck(GenerationStage::cases());
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $stages = array_values(array_filter(
            GenerationStage::cases(),
            fn (GenerationStage $stage): bool => $stage !== GenerationStage::ReviewRepair,
        ));
        $this->replaceStageCheck($stages);
    }

    /** @param array<int, GenerationStage> $stages */
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
