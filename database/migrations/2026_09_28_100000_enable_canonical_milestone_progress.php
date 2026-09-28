<?php

use App\Enums\EventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->replaceEventTypeConstraint(EventType::cases());
        }

        $this->replaceCompletionIndexes();
    }

    public function down(): void
    {
        $this->dropCompletionIndexes();

        $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_milestone_completion_unique ON {$table} (novel_id, novel_outline_milestone_id) WHERE event_type = 'story_arc_beat_milestone_completed' AND status = 'active'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_beat_completion_unique ON {$table} (novel_id, novel_outline_beat_id) WHERE event_type = 'story_arc_beat_completed' AND status = 'active'"
        );

        // 可能已经存在需要保留的 Milestone Completion 审计记录；回滚索引实现时
        // 不收窄 PostgreSQL CHECK。若需移除该事件类型，必须另做数据审计迁移。
    }

    private function replaceCompletionIndexes(): void
    {
        $this->dropCompletionIndexes();

        $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_milestone_completion_unique ON {$table} (subject_id, novel_outline_beat_id, novel_outline_milestone_id) WHERE event_type = 'story_arc_beat_milestone_completed' AND status = 'active'"
        );
        DB::statement(
            "CREATE UNIQUE INDEX story_events_active_beat_completion_unique ON {$table} (subject_id, novel_outline_beat_id) WHERE event_type = 'story_arc_beat_completed' AND status = 'active'"
        );
    }

    private function dropCompletionIndexes(): void
    {
        DB::statement('DROP INDEX IF EXISTS story_events_active_milestone_completion_unique');
        DB::statement('DROP INDEX IF EXISTS story_events_active_beat_completion_unique');
    }

    /** @param array<int, EventType> $types */
    private function replaceEventTypeConstraint(array $types): void
    {
        $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
        $values = collect($types)
            ->map(fn (EventType $type): string => DB::connection()->getPdo()->quote($type->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT story_events_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_type_check CHECK (event_type IN ({$values}))");
    }
};
