<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 把运行态 Volume、Arc、Plan 与 Completion Event 接到权威关系化大纲。
     */
    public function up(): void
    {
        $this->addCurrentOutlinePointer();

        Schema::table('volumes', function (Blueprint $table) {
            $table->foreignId('source_outline_volume_id')
                ->unique()
                ->constrained('novel_outline_volumes')
                ->restrictOnDelete();
        });

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->foreignId('source_outline_arc_id')
                ->unique()
                ->constrained('novel_outline_arcs')
                ->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->unique(['volume_id', 'sequence'], 'story_arcs_volume_sequence_unique');
        });

        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->foreignId('novel_outline_id')->constrained('novel_outlines')->restrictOnDelete();
            $table->unsignedBigInteger('primary_outline_arc_id');
            $table->unsignedBigInteger('primary_outline_beat_id');
            $table->unsignedBigInteger('primary_outline_milestone_id');
            $table->jsonb('character_candidates')->default('[]');

            // 三个组合外键把 Plan 的 Primary Target 固定为同一版本中的完整父链。
            $table->foreign(
                ['primary_outline_arc_id', 'novel_outline_id'],
                'chapter_plans_outline_arc_chain_fk',
            )->references(['id', 'novel_outline_id'])->on('novel_outline_arcs')->restrictOnDelete();
            $table->foreign(
                ['primary_outline_beat_id', 'primary_outline_arc_id', 'novel_outline_id'],
                'chapter_plans_outline_beat_chain_fk',
            )->references(['id', 'novel_outline_arc_id', 'novel_outline_id'])->on('novel_outline_beats')->restrictOnDelete();
            $table->foreign(
                ['primary_outline_milestone_id', 'primary_outline_beat_id', 'novel_outline_id'],
                'chapter_plans_outline_milestone_chain_fk',
            )->references(['id', 'novel_outline_beat_id', 'novel_outline_id'])->on('novel_outline_milestones')->restrictOnDelete();
        });

        Schema::table('story_events', function (Blueprint $table) {
            $table->foreignId('novel_outline_id')->nullable()->constrained('novel_outlines')->restrictOnDelete();
            $table->unsignedBigInteger('novel_outline_arc_id')->nullable();
            $table->unsignedBigInteger('novel_outline_beat_id')->nullable();
            $table->unsignedBigInteger('novel_outline_milestone_id')->nullable();

            // 非进度事件可以全部为空；一旦填写则数据库保证节点来自同一版本和父链。
            $table->foreign(
                ['novel_outline_arc_id', 'novel_outline_id'],
                'story_events_outline_arc_chain_fk',
            )->references(['id', 'novel_outline_id'])->on('novel_outline_arcs')->restrictOnDelete();
            $table->foreign(
                ['novel_outline_beat_id', 'novel_outline_arc_id', 'novel_outline_id'],
                'story_events_outline_beat_chain_fk',
            )->references(['id', 'novel_outline_arc_id', 'novel_outline_id'])->on('novel_outline_beats')->restrictOnDelete();
            $table->foreign(
                ['novel_outline_milestone_id', 'novel_outline_beat_id', 'novel_outline_id'],
                'story_events_outline_milestone_chain_fk',
            )->references(['id', 'novel_outline_beat_id', 'novel_outline_id'])->on('novel_outline_milestones')->restrictOnDelete();
        });

        Schema::table('characters', function (Blueprint $table) {
            $table->foreignId('source_chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->string('source_candidate_key')->nullable();
            $table->unique(
                ['novel_id', 'source_chapter_id', 'source_candidate_key'],
                'characters_canonical_source_unique',
            );
        });

        $this->addDatabaseChecksAndIndexes();
    }

    /**
     * 移除关系化来源外键；只支持通过重新建库恢复旧开发结构。
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $storyEvents = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
            $storyArcs = DB::connection()->getSchemaGrammar()->wrapTable('story_arcs');
            DB::statement("ALTER TABLE {$storyEvents} DROP CONSTRAINT story_events_outline_completion_check");
            DB::statement("ALTER TABLE {$storyArcs} DROP CONSTRAINT story_arcs_sequence_check");
        }

        DB::statement('DROP INDEX IF EXISTS story_events_active_milestone_completion_unique');
        DB::statement('DROP INDEX IF EXISTS story_events_active_beat_completion_unique');
        DB::statement('DROP INDEX IF EXISTS story_events_outline_progress_idx');

        Schema::table('characters', function (Blueprint $table) {
            $table->dropUnique('characters_canonical_source_unique');
            $table->dropConstrainedForeignId('source_chapter_id');
            $table->dropColumn('source_candidate_key');
        });

        Schema::table('story_events', function (Blueprint $table) {
            $table->dropForeign('story_events_outline_milestone_chain_fk');
            $table->dropForeign('story_events_outline_beat_chain_fk');
            $table->dropForeign('story_events_outline_arc_chain_fk');
            $table->dropConstrainedForeignId('novel_outline_id');
            $table->dropColumn([
                'novel_outline_arc_id',
                'novel_outline_beat_id',
                'novel_outline_milestone_id',
            ]);
        });

        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->dropForeign('chapter_plans_outline_milestone_chain_fk');
            $table->dropForeign('chapter_plans_outline_beat_chain_fk');
            $table->dropForeign('chapter_plans_outline_arc_chain_fk');
            $table->dropConstrainedForeignId('novel_outline_id');
            $table->dropColumn([
                'primary_outline_arc_id',
                'primary_outline_beat_id',
                'primary_outline_milestone_id',
                'character_candidates',
            ]);
        });

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->dropUnique('story_arcs_volume_sequence_unique');
            $table->dropConstrainedForeignId('source_outline_arc_id');
            $table->dropColumn('sequence');
        });

        Schema::table('volumes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('source_outline_volume_id');
        });

        Schema::withoutForeignKeyConstraints(function (): void {
            Schema::table('novels', function (Blueprint $table) {
                $table->dropConstrainedForeignId('current_outline_id');
            });
        });
    }

    /**
     * 增加 Novel 当前版本指针，并兼容 SQLite 的 ALTER TABLE 能力。
     */
    private function addCurrentOutlinePointer(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $grammar = DB::connection()->getSchemaGrammar();
            $novels = $grammar->wrapTable('novels');
            $outlines = $grammar->wrapTable('novel_outlines');
            DB::statement(
                "ALTER TABLE {$novels} ADD COLUMN current_outline_id INTEGER NULL REFERENCES {$outlines}(id) ON DELETE SET NULL"
            );

            return;
        }

        Schema::table('novels', function (Blueprint $table) {
            $table->foreignId('current_outline_id')
                ->nullable()
                ->constrained('novel_outlines')
                ->nullOnDelete();
        });
    }

    /**
     * 为正式进度事件增加唯一索引，并在 PostgreSQL 约束条件必填外键。
     */
    private function addDatabaseChecksAndIndexes(): void
    {
        $grammar = DB::connection()->getSchemaGrammar();
        $storyEvents = $grammar->wrapTable('story_events');

        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_milestone_completion_unique ON {$storyEvents} (novel_id, novel_outline_milestone_id) WHERE event_type = 'story_arc_beat_milestone_completed' AND status = 'active'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_beat_completion_unique ON {$storyEvents} (novel_id, novel_outline_beat_id) WHERE event_type = 'story_arc_beat_completed' AND status = 'active'"
        );
        DB::statement(
            "CREATE INDEX story_events_outline_progress_idx ON {$storyEvents} (novel_outline_id, novel_outline_beat_id, novel_outline_milestone_id) WHERE status = 'active'"
        );

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $storyArcs = $grammar->wrapTable('story_arcs');
        DB::statement("ALTER TABLE {$storyArcs} ADD CONSTRAINT story_arcs_sequence_check CHECK (sequence > 0)");
        DB::statement(
            "ALTER TABLE {$storyEvents} ADD CONSTRAINT story_events_outline_completion_check CHECK ("
            ."(event_type = 'story_arc_beat_completed' AND novel_outline_id IS NOT NULL AND novel_outline_arc_id IS NOT NULL AND novel_outline_beat_id IS NOT NULL AND novel_outline_milestone_id IS NULL) OR "
            ."(event_type = 'story_arc_beat_milestone_completed' AND novel_outline_id IS NOT NULL AND novel_outline_arc_id IS NOT NULL AND novel_outline_beat_id IS NOT NULL AND novel_outline_milestone_id IS NOT NULL) OR "
            ."(event_type NOT IN ('story_arc_beat_completed', 'story_arc_beat_milestone_completed') AND novel_outline_id IS NULL AND novel_outline_arc_id IS NULL AND novel_outline_beat_id IS NULL AND novel_outline_milestone_id IS NULL)"
            .')'
        );
    }
};
