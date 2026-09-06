<?php

use App\Enums\ChapterStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('volume_id')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('title');
            $table->string('status')->default(ChapterStatus::Planned->value);
            $table->unsignedBigInteger('canonical_artifact_id')->nullable();
            $table->unsignedBigInteger('word_count')->default(0);
            $table->text('summary')->nullable();
            $table->timestamps();

            $table->foreign(['volume_id', 'novel_id'])
                ->references(['id', 'novel_id'])
                ->on('volumes');
            $table->unique(['novel_id', 'sequence']);
            $table->index(['novel_id', 'status']);
            $table->index('canonical_artifact_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('chapters');
            $statuses = collect(ChapterStatus::cases())
                ->map(fn (ChapterStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapters_sequence_check CHECK (sequence > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapters_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chapters');
    }
};
