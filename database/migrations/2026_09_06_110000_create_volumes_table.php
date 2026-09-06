<?php

use App\Enums\VolumeStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volumes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->text('goal');
            $table->text('climax');
            $table->unsignedBigInteger('target_words');
            $table->string('status')->default(VolumeStatus::Planned->value);
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->unique(['novel_id', 'sequence']);
            $table->index(['novel_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('volumes');
            $statuses = collect(VolumeStatus::cases())
                ->map(fn (VolumeStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT volumes_sequence_check CHECK (sequence > 0)"
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT volumes_target_words_check CHECK (target_words > 0)"
            );
            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT volumes_status_check CHECK (status IN ({$statuses}))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('volumes');
    }
};
