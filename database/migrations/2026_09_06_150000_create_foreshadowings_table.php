<?php

use App\Enums\ForeshadowingImportance;
use App\Enums\ForeshadowingStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('story_arcs', function (Blueprint $table) {
            $table->unique(['id', 'novel_id'], 'story_arcs_id_novel_id_unique');
        });

        Schema::create('foreshadowings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description');
            $table->unsignedBigInteger('setup_chapter_id')->nullable();
            $table->text('promised_payoff');
            $table->unsignedInteger('due_from_chapter');
            $table->unsignedInteger('due_to_chapter');
            $table->string('importance')->default(ForeshadowingImportance::Medium->value);
            $table->string('status')->default(ForeshadowingStatus::Idea->value);
            $table->unsignedBigInteger('owner_arc_id')->nullable();
            $table->unsignedInteger('reinforce_count')->default(0);
            $table->unsignedBigInteger('payoff_chapter_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign(['owner_arc_id', 'novel_id'])
                ->references(['id', 'novel_id'])
                ->on('story_arcs');
            $table->index(['novel_id', 'status']);
            $table->index(['novel_id', 'importance']);
            $table->index(['novel_id', 'due_from_chapter', 'due_to_chapter']);
            $table->index('setup_chapter_id');
            $table->index('payoff_chapter_id');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('foreshadowings');
            $statuses = collect(ForeshadowingStatus::cases())
                ->map(fn (ForeshadowingStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');
            $importances = collect(ForeshadowingImportance::cases())
                ->map(fn (ForeshadowingImportance $importance): string => DB::connection()->getPdo()->quote($importance->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadowings_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadowings_importance_check CHECK (importance IN ({$importances}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadowings_due_window_check CHECK (due_from_chapter > 0 AND due_to_chapter >= due_from_chapter)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadowings_setup_chapter_check CHECK (setup_chapter_id IS NULL OR setup_chapter_id > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT foreshadowings_payoff_chapter_check CHECK (payoff_chapter_id IS NULL OR payoff_chapter_id > 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('foreshadowings');

        Schema::table('story_arcs', function (Blueprint $table) {
            $table->dropUnique('story_arcs_id_novel_id_unique');
        });
    }
};
