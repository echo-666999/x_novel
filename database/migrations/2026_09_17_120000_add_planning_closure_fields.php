<?php

use App\Enums\EventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->jsonb('arc_contributions')->default('[]');
            $table->jsonb('world_entity_candidates')->default('[]');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->jsonb('canonical_metadata')->nullable();
        });

        Schema::table('world_entities', function (Blueprint $table) {
            $table->foreignId('source_chapter_id')->nullable()->constrained('chapters')->nullOnDelete();
            $table->string('source_candidate_key')->nullable();
            $table->unique(
                ['novel_id', 'source_chapter_id', 'source_candidate_key'],
                'world_entities_canonical_source_unique',
            );
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
            $types = collect(EventType::cases())
                ->map(fn (EventType $type): string => DB::connection()->getPdo()->quote($type->value))
                ->implode(', ');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT story_events_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_type_check CHECK (event_type IN ({$types}))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
            $types = collect(EventType::cases())
                ->reject(fn (EventType $type): bool => in_array($type, [EventType::WorldEntityIntroduced, EventType::StoryArcBeatCompleted], true))
                ->map(fn (EventType $type): string => DB::connection()->getPdo()->quote($type->value))
                ->implode(', ');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT story_events_type_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_type_check CHECK (event_type IN ({$types}))");
        }

        Schema::table('world_entities', function (Blueprint $table) {
            $table->dropUnique('world_entities_canonical_source_unique');
            $table->dropConstrainedForeignId('source_chapter_id');
            $table->dropColumn('source_candidate_key');
        });

        Schema::table('chapters', function (Blueprint $table) {
            $table->dropColumn('canonical_metadata');
        });

        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->dropColumn(['arc_contributions', 'world_entity_candidates']);
        });
    }
};
