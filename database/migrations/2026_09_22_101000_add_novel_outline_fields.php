<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement(
                'ALTER TABLE novels ADD COLUMN current_outline_id INTEGER NULL REFERENCES novel_outlines(id) ON DELETE SET NULL'
            );
        } else {
            Schema::table('novels', function (Blueprint $table) {
                $table->foreignId('current_outline_id')
                    ->nullable()
                    ->constrained('novel_outlines')
                    ->nullOnDelete();
            });
        }

        Schema::table('volumes', function (Blueprint $table) {
            $table->string('outline_key')->nullable();
        });

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->string('outline_key')->nullable();
            $table->unsignedInteger('sequence')->default(1);
        });

        $sequences = [];
        DB::table('story_arcs')
            ->select(['id', 'novel_id', 'volume_id'])
            ->orderBy('novel_id')
            ->orderBy('volume_id')
            ->orderBy('id')
            ->each(function (object $arc) use (&$sequences): void {
                $group = $arc->volume_id === null
                    ? "novel:{$arc->novel_id}:unassigned"
                    : "volume:{$arc->volume_id}";
                $sequences[$group] = ($sequences[$group] ?? 0) + 1;

                DB::table('story_arcs')
                    ->where('id', $arc->id)
                    ->update(['sequence' => $sequences[$group]]);
            });

        DB::statement(
            'CREATE UNIQUE INDEX volumes_novel_outline_key_unique ON volumes (novel_id, outline_key) WHERE outline_key IS NOT NULL'
        );
        DB::statement(
            'CREATE UNIQUE INDEX story_arcs_novel_outline_key_unique ON story_arcs (novel_id, outline_key) WHERE outline_key IS NOT NULL'
        );

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->unique(['volume_id', 'sequence'], 'story_arcs_volume_sequence_unique');
        });

        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->foreignId('novel_outline_id')
                ->nullable()
                ->constrained('novel_outlines')
                ->nullOnDelete();
            $table->jsonb('character_candidates')->default('[]');
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('source_chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->string('source_candidate_key')->nullable();
            $table->unique(
                ['novel_id', 'source_chapter_id', 'source_candidate_key'],
                'characters_canonical_source_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $storyArcs = DB::connection()->getSchemaGrammar()->wrapTable('story_arcs');
            DB::statement("ALTER TABLE {$storyArcs} ADD CONSTRAINT story_arcs_sequence_check CHECK (sequence > 0)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $storyArcs = DB::connection()->getSchemaGrammar()->wrapTable('story_arcs');
            DB::statement("ALTER TABLE {$storyArcs} DROP CONSTRAINT story_arcs_sequence_check");
        }

        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_canonical_source_unique');
            $table->dropConstrainedForeignId('source_chapter_id');
            $table->dropColumn('source_candidate_key');
        });

        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('novel_outline_id');
            $table->dropColumn('character_candidates');
        });

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->dropUnique('story_arcs_volume_sequence_unique');
        });
        DB::statement('DROP INDEX IF EXISTS story_arcs_novel_outline_key_unique');
        DB::statement('DROP INDEX IF EXISTS volumes_novel_outline_key_unique');

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->dropColumn(['outline_key', 'sequence']);
        });

        Schema::table('volumes', function (Blueprint $table) {
            $table->dropColumn('outline_key');
        });

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropConstrainedForeignId('current_outline_id');
            });
        });
    }
};
