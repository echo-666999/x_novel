<?php

use App\Enums\ArtifactType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 扩展 PostgreSQL CHECK，允许持久化 Structure 与逐 Arc Beats Artifact。 */
    public function up(): void
    {
        $this->replaceArtifactTypeCheck(ArtifactType::cases());
    }

    /**
     * 回退会收窄 CHECK；已存在新类型数据时必须先显式归档或清理，禁止静默制造不一致。
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $newTypes = [
            ArtifactType::OutlineStructure,
            ArtifactType::OutlineArcBeats,
        ];

        if (DB::table('generation_artifacts')->whereIn('type', array_map(
            fn (ArtifactType $type): string => $type->value,
            $newTypes,
        ))->exists()) {
            throw new RuntimeException('存在 outline_structure 或 outline_arc_beats Artifact；请先停止新批次并显式归档或清理后再回滚。');
        }

        $types = array_values(array_filter(
            ArtifactType::cases(),
            fn (ArtifactType $type): bool => ! in_array($type, $newTypes, true),
        ));

        $this->replaceArtifactTypeCheck($types);
    }

    /** 用枚举值原子替换 PostgreSQL Artifact Type CHECK。 */
    private function replaceArtifactTypeCheck(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $connection = DB::connection();
        if ($connection->getSchemaGrammar() === null) {
            $connection->useDefaultSchemaGrammar();
        }
        $table = $connection->getSchemaGrammar()->wrapTable('generation_artifacts');
        $values = collect($types)
            ->map(fn (ArtifactType $type): string => $connection->getPdo()->quote($type->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS generation_artifacts_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT generation_artifacts_type_check CHECK (type IN ({$values}))");
    }
};
