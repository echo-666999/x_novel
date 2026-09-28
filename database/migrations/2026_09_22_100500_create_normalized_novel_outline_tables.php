<?php

use App\Enums\StoryArcType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建 Volume、Arc、Beat、Milestone 四层关系表及完整父链约束。
     */
    public function up(): void
    {
        Schema::create('novel_outline_volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_outline_id')->constrained()->cascadeOnDelete();
            $table->string('volume_key');
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->text('goal');
            $table->text('climax');
            $table->unsignedBigInteger('target_words');
            $table->timestamps();

            $table->unique(['id', 'novel_outline_id'], 'outline_volumes_id_outline_unique');
            $table->unique(['novel_outline_id', 'volume_key'], 'outline_volumes_key_unique');
            $table->unique(['novel_outline_id', 'sequence'], 'outline_volumes_sequence_unique');
        });

        Schema::create('novel_outline_arcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_outline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_outline_volume_id')->constrained()->cascadeOnDelete();
            $table->string('arc_key');
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('mainline_sequence')->nullable();
            $table->string('type');
            $table->string('title');
            $table->text('goal');
            $table->text('stakes');
            $table->jsonb('completion_conditions')->default('[]');
            $table->timestamps();

            $table->unique(['id', 'novel_outline_id'], 'outline_arcs_id_outline_unique');
            $table->unique(['id', 'novel_outline_volume_id', 'novel_outline_id'], 'outline_arcs_parent_chain_unique');
            $table->unique(['novel_outline_id', 'arc_key'], 'outline_arcs_key_unique');
            $table->unique(['novel_outline_volume_id', 'sequence'], 'outline_arcs_sequence_unique');
            $table->foreign(['novel_outline_volume_id', 'novel_outline_id'], 'outline_arcs_volume_chain_fk')
                ->references(['id', 'novel_outline_id'])->on('novel_outline_volumes')->cascadeOnDelete();
        });

        Schema::create('novel_outline_beats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_outline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_outline_arc_id')->constrained()->cascadeOnDelete();
            $table->string('beat_key');
            $table->unsignedInteger('sequence');
            $table->unsignedInteger('mainline_sequence')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->unsignedInteger('chapter_budget_min');
            $table->unsignedInteger('chapter_budget_max')->nullable();
            $table->jsonb('acceptance_criteria')->default('[]');
            $table->jsonb('must_include')->default('[]');
            $table->jsonb('must_not_include')->default('[]');
            $table->jsonb('character_candidates')->default('[]');
            $table->jsonb('world_entity_candidates')->default('[]');
            $table->unsignedBigInteger('handoff_next_beat_id')->nullable();
            $table->string('handoff_transition_mode')->nullable();
            $table->text('handoff_exit_result')->nullable();
            $table->text('handoff_next_trigger')->nullable();
            $table->jsonb('handoff_carried_states')->default('[]');
            $table->jsonb('handoff_open_threads')->default('[]');
            $table->jsonb('handoff_required_transition')->default('[]');
            $table->jsonb('handoff_forbidden_jump')->default('[]');
            $table->timestamps();

            $table->unique(['id', 'novel_outline_id'], 'outline_beats_id_outline_unique');
            $table->unique(['id', 'novel_outline_arc_id', 'novel_outline_id'], 'outline_beats_parent_chain_unique');
            $table->unique(['novel_outline_id', 'beat_key'], 'outline_beats_key_unique');
            $table->unique(['novel_outline_arc_id', 'sequence'], 'outline_beats_sequence_unique');
            $table->foreign(['novel_outline_arc_id', 'novel_outline_id'], 'outline_beats_arc_chain_fk')
                ->references(['id', 'novel_outline_id'])->on('novel_outline_arcs')->cascadeOnDelete();
        });

        // Handoff 指向同一 Outline 的 Beat；相邻顺序仍由领域校验器负责。
        Schema::table('novel_outline_beats', function (Blueprint $table) {
            $table->foreign(['handoff_next_beat_id', 'novel_outline_id'], 'outline_beats_handoff_chain_fk')
                ->references(['id', 'novel_outline_id'])->on('novel_outline_beats')->restrictOnDelete();
        });

        Schema::create('novel_outline_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_outline_id')->constrained()->cascadeOnDelete();
            $table->foreignId('novel_outline_beat_id')->constrained()->cascadeOnDelete();
            $table->string('milestone_key');
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->text('objective');
            $table->jsonb('acceptance_criteria')->default('[]');
            $table->jsonb('must_include')->default('[]');
            $table->jsonb('must_not_include')->default('[]');
            $table->timestamps();

            $table->unique(['id', 'novel_outline_beat_id', 'novel_outline_id'], 'outline_milestones_parent_chain_unique');
            $table->unique(['novel_outline_id', 'milestone_key'], 'outline_milestones_key_unique');
            $table->unique(['novel_outline_beat_id', 'sequence'], 'outline_milestones_sequence_unique');
            $table->foreign(['novel_outline_beat_id', 'novel_outline_id'], 'outline_milestones_beat_chain_fk')
                ->references(['id', 'novel_outline_id'])->on('novel_outline_beats')->cascadeOnDelete();
        });

        $this->addPostgreSqlConstraints();
    }

    /**
     * 按子节点到父节点的顺序删除关系化大纲表。
     */
    public function down(): void
    {
        Schema::dropIfExists('novel_outline_milestones');
        Schema::dropIfExists('novel_outline_beats');
        Schema::dropIfExists('novel_outline_arcs');
        Schema::dropIfExists('novel_outline_volumes');
    }

    /**
     * 为 PostgreSQL 增加 SQLite 无法完整表达的 CHECK 与部分索引。
     */
    private function addPostgreSqlConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $grammar = DB::connection()->getSchemaGrammar();
        $volumes = $grammar->wrapTable('novel_outline_volumes');
        $arcs = $grammar->wrapTable('novel_outline_arcs');
        $beats = $grammar->wrapTable('novel_outline_beats');
        $milestones = $grammar->wrapTable('novel_outline_milestones');
        $types = collect(StoryArcType::cases())
            ->map(fn (StoryArcType $type): string => DB::connection()->getPdo()->quote($type->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$volumes} ADD CONSTRAINT outline_volumes_sequence_check CHECK (sequence > 0)");
        DB::statement("ALTER TABLE {$volumes} ADD CONSTRAINT outline_volumes_target_words_check CHECK (target_words > 0)");
        DB::statement("ALTER TABLE {$arcs} ADD CONSTRAINT outline_arcs_sequence_check CHECK (sequence > 0)");
        DB::statement("ALTER TABLE {$arcs} ADD CONSTRAINT outline_arcs_mainline_sequence_check CHECK (mainline_sequence IS NULL OR mainline_sequence > 0)");
        DB::statement("ALTER TABLE {$arcs} ADD CONSTRAINT outline_arcs_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE {$arcs} ADD CONSTRAINT outline_arcs_mainline_type_check CHECK ((type = 'main' AND mainline_sequence IS NOT NULL) OR (type = 'subplot' AND mainline_sequence IS NULL))");
        DB::statement("ALTER TABLE {$arcs} ADD CONSTRAINT outline_arcs_completion_conditions_check CHECK (jsonb_typeof(completion_conditions) = 'array')");
        DB::statement("ALTER TABLE {$beats} ADD CONSTRAINT outline_beats_sequence_check CHECK (sequence > 0)");
        DB::statement("ALTER TABLE {$beats} ADD CONSTRAINT outline_beats_mainline_sequence_check CHECK (mainline_sequence IS NULL OR mainline_sequence > 0)");
        DB::statement("ALTER TABLE {$beats} ADD CONSTRAINT outline_beats_budget_check CHECK (chapter_budget_min >= 1 AND (chapter_budget_max IS NULL OR chapter_budget_max >= chapter_budget_min))");
        DB::statement("ALTER TABLE {$milestones} ADD CONSTRAINT outline_milestones_sequence_check CHECK (sequence > 0)");

        // PostgreSQL Partial Unique Index 允许所有 Subplot 节点保持 mainline_sequence=NULL。
        DB::statement("CREATE UNIQUE INDEX outline_arcs_mainline_unique ON {$arcs} (novel_outline_id, mainline_sequence) WHERE mainline_sequence IS NOT NULL");
        DB::statement("CREATE UNIQUE INDEX outline_beats_mainline_unique ON {$beats} (novel_outline_id, mainline_sequence) WHERE mainline_sequence IS NOT NULL");
        DB::statement("CREATE INDEX outline_milestones_beat_sequence_idx ON {$milestones} (novel_outline_beat_id, sequence)");
    }
};
