<?php

use App\Enums\StoryArcStatus;
use App\Enums\StoryArcType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('volumes', function (Blueprint $table) {
            $table->unique(['id', 'novel_id'], 'volumes_id_novel_id_unique');
        });

        Schema::create('story_arcs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('volume_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('goal');
            $table->text('stakes');
            $table->jsonb('beats')->default('[]');
            $table->jsonb('completion_conditions')->default('[]');
            $table->decimal('progress', 5, 4)->default(0);
            $table->string('status')->default(StoryArcStatus::Planned->value);
            $table->timestamps();

            $table->index(['novel_id', 'status']);
            $table->index(['volume_id', 'type']);
            $table->foreign(['volume_id', 'novel_id'])
                ->references(['id', 'novel_id'])
                ->on('volumes');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('story_arcs');
            $types = collect(StoryArcType::cases())
                ->map(fn (StoryArcType $type): string => DB::connection()->getPdo()->quote($type->value))
                ->implode(', ');
            $statuses = collect(StoryArcStatus::cases())
                ->map(fn (StoryArcStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_arcs_progress_check CHECK (progress >= 0 AND progress <= 1)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_arcs_type_check CHECK (type IN ({$types}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_arcs_status_check CHECK (status IN ({$statuses}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('story_arcs');

        Schema::table('volumes', function (Blueprint $table) {
            $table->dropUnique('volumes_id_novel_id_unique');
        });
    }
};
