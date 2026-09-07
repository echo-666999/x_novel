<?php

use App\Enums\ReviewDecision;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_run_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('artifact_id')->unique()->constrained('generation_artifacts')->cascadeOnDelete();
            $table->string('decision');
            $table->decimal('score', 5, 2);
            $table->decimal('continuity_score', 5, 2);
            $table->decimal('plan_score', 5, 2);
            $table->decimal('character_score', 5, 2);
            $table->decimal('progress_score', 5, 2);
            $table->decimal('repetition_score', 5, 2);
            $table->decimal('pacing_score', 5, 2);
            $table->decimal('style_score', 5, 2);
            $table->json('findings');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['decision', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $values = collect(ReviewDecision::cases())->map(fn ($case) => DB::connection()->getPdo()->quote($case->value))->implode(', ');
            DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_decision_check CHECK (decision IN ({$values}))");
            foreach (['score', 'continuity_score', 'plan_score', 'character_score', 'progress_score', 'repetition_score', 'pacing_score', 'style_score'] as $column) {
                DB::statement("ALTER TABLE reviews ADD CONSTRAINT reviews_{$column}_check CHECK ({$column} >= 0 AND {$column} <= 100)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
