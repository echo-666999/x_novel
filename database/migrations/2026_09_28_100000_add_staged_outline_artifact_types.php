<?php

use App\Enums\ArtifactType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** 扩展 PostgreSQL CHECK，使三个分阶段 Artifact 类型可以持久化。 */
    public function up(): void
    {
        $this->replaceArtifactTypeCheck(ArtifactType::cases());
    }

    /** 回退到迁移前的 Artifact 类型集合。 */
    public function down(): void
    {
        $types = array_values(array_filter(
            ArtifactType::cases(),
            fn (ArtifactType $type): bool => ! in_array($type, [
                ArtifactType::OutlineFoundation,
                ArtifactType::OutlineSkeleton,
                ArtifactType::OutlineBeatDetail,
            ], true),
        ));

        $this->replaceArtifactTypeCheck($types);
    }

    /** 用枚举值原子替换 PostgreSQL Artifact Type CHECK。 */
    private function replaceArtifactTypeCheck(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getSchemaGrammar()->wrapTable('generation_artifacts');
        $values = collect($types)
            ->map(fn (ArtifactType $type): string => DB::connection()->getPdo()->quote($type->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS generation_artifacts_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT generation_artifacts_type_check CHECK (type IN ({$values}))");
    }
};
