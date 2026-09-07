<?php

use App\Enums\EventType;
use App\Enums\StoryEventStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->constrained()->restrictOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->string('subject_id')->nullable();
            $table->jsonb('payload');
            $table->jsonb('evidence');
            $table->string('story_time')->nullable();
            $table->unsignedInteger('state_version');
            $table->string('status')->default(StoryEventStatus::Active->value);
            $table->timestampTz('invalidated_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['novel_id', 'status', 'created_at']);
            $table->index(['chapter_id', 'status']);
            $table->index('scene_id');
            $table->index(['novel_id', 'event_type']);
            $table->index(['novel_id', 'subject_type', 'subject_id']);
            $table->index(['novel_id', 'state_version']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
            $types = $this->quotedValues(EventType::cases());
            $statuses = $this->quotedValues(StoryEventStatus::cases());
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_type_check CHECK (event_type IN ({$types}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_evidence_check CHECK (jsonb_typeof(evidence) = 'array' AND jsonb_array_length(evidence) > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_invalidation_check CHECK ((status = 'active' AND invalidated_at IS NULL) OR (status = 'invalidated' AND invalidated_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_events');
    }

    /** @param array<int, BackedEnum> $cases */
    private function quotedValues(array $cases): string
    {
        return collect($cases)->map(fn (BackedEnum $case): string => DB::connection()->getPdo()->quote($case->value))->implode(', ');
    }
};
