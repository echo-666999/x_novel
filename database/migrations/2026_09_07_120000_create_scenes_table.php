<?php

use App\Enums\SceneStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->foreignId('pov_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('location')->nullable();
            $table->string('time_anchor')->nullable();
            $table->text('goal');
            $table->text('conflict');
            $table->text('turn');
            $table->text('outcome');
            $table->string('status')->default(SceneStatus::Planned->value);
            $table->unsignedBigInteger('current_artifact_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['chapter_id', 'sequence']);
            $table->index(['chapter_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('scenes');
            $statuses = collect(SceneStatus::cases())
                ->map(fn (SceneStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT scenes_sequence_check CHECK (sequence > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT scenes_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scenes');
    }
};
