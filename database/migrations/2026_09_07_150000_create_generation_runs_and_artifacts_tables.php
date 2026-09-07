<?php

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('generation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('chapter_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('scene_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('scope_type');
            $table->unsignedBigInteger('scope_id');
            $table->string('stage');
            $table->string('status')->default(RunStatus::Queued->value);
            $table->unsignedInteger('attempt')->default(1);
            $table->string('idempotency_key')->unique();
            $table->char('input_hash', 64);
            $table->unsignedBigInteger('state_version')->nullable();
            $table->unsignedBigInteger('bible_version')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('model_policy')->nullable();
            $table->json('context_snapshot')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['novel_id', 'status']);
            $table->index(['chapter_id', 'stage']);
            $table->index(['scope_type', 'scope_id']);
            $table->index('input_hash');
        });

        Schema::create('generation_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('generation_run_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->unsignedInteger('version')->default(1);
            $table->longText('content')->nullable();
            $table->json('data')->nullable();
            $table->char('checksum', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['generation_run_id', 'type', 'version']);
            $table->index('checksum');
        });

        Schema::table('usage_records', function (Blueprint $table) {
            $table->foreign('generation_run_id')
                ->references('id')
                ->on('generation_runs')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $this->addPostgresChecks();
        }
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropForeign(['generation_run_id']);
        });

        Schema::dropIfExists('generation_artifacts');
        Schema::dropIfExists('generation_runs');
    }

    private function addPostgresChecks(): void
    {
        $connection = DB::connection();
        $runs = $connection->getSchemaGrammar()->wrapTable('generation_runs');
        $artifacts = $connection->getSchemaGrammar()->wrapTable('generation_artifacts');
        $stages = $this->quotedValues(GenerationStage::cases());
        $statuses = $this->quotedValues(RunStatus::cases());
        $types = $this->quotedValues(ArtifactType::cases());

        DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT generation_runs_stage_check CHECK (stage IN ({$stages}))");
        DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT generation_runs_status_check CHECK (status IN ({$statuses}))");
        DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT generation_runs_attempt_check CHECK (attempt > 0)");
        DB::statement("ALTER TABLE {$runs} ADD CONSTRAINT generation_runs_timing_check CHECK (finished_at IS NULL OR started_at IS NULL OR finished_at >= started_at)");
        DB::statement("ALTER TABLE {$artifacts} ADD CONSTRAINT generation_artifacts_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE {$artifacts} ADD CONSTRAINT generation_artifacts_version_check CHECK (version > 0)");
    }

    /** @param array<int, BackedEnum> $cases */
    private function quotedValues(array $cases): string
    {
        return collect($cases)
            ->map(fn (BackedEnum $case): string => DB::connection()->getPdo()->quote($case->value))
            ->implode(', ');
    }
};
