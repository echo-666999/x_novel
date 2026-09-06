<?php

use App\Enums\CharacterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('characters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->jsonb('aliases')->default('[]');
            $table->string('role');
            $table->jsonb('profile')->default('{}');
            $table->text('motivation');
            $table->jsonb('personality')->default('{}');
            $table->jsonb('abilities')->default('{}');
            $table->jsonb('knowledge')->default('{}');
            $table->jsonb('current_state')->default('{}');
            $table->jsonb('locked_fields')->default('[]');
            $table->string('status')->default(CharacterStatus::Active->value);
            $table->timestamps();

            $table->index(['novel_id', 'status']);
            $table->index(['novel_id', 'role']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('characters');
            $statuses = collect(CharacterStatus::cases())
                ->map(fn (CharacterStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT characters_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('characters');
    }
};
