<?php

use App\Enums\EventType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->replaceConstraint(EventType::cases());
    }

    public function down(): void
    {
        $this->replaceConstraint(array_values(array_filter(
            EventType::cases(),
            fn (EventType $type): bool => $type !== EventType::CharacterIntroduced,
        )));
    }

    /** @param array<int, EventType> $types */
    private function replaceConstraint(array $types): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $table = DB::connection()->getSchemaGrammar()->wrapTable('story_events');
        $values = collect($types)
            ->map(fn (EventType $type): string => DB::connection()->getPdo()->quote($type->value))
            ->implode(', ');

        DB::statement("ALTER TABLE {$table} DROP CONSTRAINT story_events_type_check");
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_events_type_check CHECK (event_type IN ({$values}))");
    }
};
