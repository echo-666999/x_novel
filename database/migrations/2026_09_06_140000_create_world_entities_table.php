<?php

use App\Enums\WorldEntityStatus;
use App\Enums\WorldEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('world_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->text('description');
            $table->jsonb('attributes')->default('{}');
            $table->jsonb('rules')->default('{}');
            $table->jsonb('current_state')->default('{}');
            $table->jsonb('locked_fields')->default('[]');
            $table->string('status')->default(WorldEntityStatus::Active->value);
            $table->timestamps();

            $table->index(['novel_id', 'type']);
            $table->index(['novel_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('world_entities');
            $types = collect(WorldEntityType::cases())
                ->map(fn (WorldEntityType $type): string => DB::connection()->getPdo()->quote($type->value))
                ->implode(', ');
            $statuses = collect(WorldEntityStatus::cases())
                ->map(fn (WorldEntityStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT world_entities_type_check CHECK (type IN ({$types}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT world_entities_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('world_entities');
    }
};
