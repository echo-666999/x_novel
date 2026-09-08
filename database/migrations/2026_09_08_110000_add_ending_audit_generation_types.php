<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $this->replaceCheck('generation_runs', 'generation_runs_stage_check', GenerationStage::cases(), 'stage');
        $this->replaceCheck('generation_artifacts', 'generation_artifacts_type_check', ArtifactType::cases(), 'type');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $stages = array_values(array_filter(GenerationStage::cases(), fn (GenerationStage $stage): bool => $stage !== GenerationStage::EndingAudit));
        $types = array_values(array_filter(ArtifactType::cases(), fn (ArtifactType $type): bool => $type !== ArtifactType::EndingAudit));

        $this->replaceCheck('generation_runs', 'generation_runs_stage_check', $stages, 'stage');
        $this->replaceCheck('generation_artifacts', 'generation_artifacts_type_check', $types, 'type');
    }

    /** @param array<int, BackedEnum> $values */
    private function replaceCheck(string $tableName, string $constraint, array $values, string $column): void
    {
        $connection = DB::connection();
        $table = $connection->getSchemaGrammar()->wrapTable($tableName);
        $quoted = collect($values)
            ->map(fn (BackedEnum $value): string => $connection->getPdo()->quote($value->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$constraint} CHECK ({$column} IN ({$quoted}))");
    }
};
