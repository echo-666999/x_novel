<?php

use App\Enums\PlanStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chapter_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chapter_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('chapter_function');
            $table->text('arc_contribution');
            $table->text('reader_promise');
            $table->unsignedInteger('target_words');
            $table->foreignId('pov_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('tone');
            $table->string('time_anchor');
            $table->string('hook_type');
            $table->jsonb('must_reveal')->default('[]');
            $table->jsonb('may_hint')->default('[]');
            $table->jsonb('must_not_reveal')->default('[]');
            $table->jsonb('required_facts')->default('[]');
            $table->jsonb('forbidden_conflicts')->default('[]');
            $table->jsonb('due_foreshadowings')->default('[]');
            $table->jsonb('scene_plans')->default('[]');
            $table->string('status')->default(PlanStatus::Draft->value);
            $table->timestamps();

            $table->unique(['chapter_id', 'version']);
            $table->index(['chapter_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('chapter_plans');
            $statuses = collect(PlanStatus::cases())
                ->map(fn (PlanStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapter_plans_version_check CHECK (version > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapter_plans_target_words_check CHECK (target_words > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapter_plans_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chapter_plans');
    }
};
