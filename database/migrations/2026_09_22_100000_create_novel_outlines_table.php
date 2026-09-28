<?php

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 创建只保存版本元数据和来源追踪的大纲版本头。
     */
    public function up(): void
    {
        Schema::create('novel_outlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default(NovelOutlineStatus::Draft->value);
            $table->string('source');
            $table->unsignedInteger('schema_version')->default(1);
            $table->string('title');
            $table->text('summary');
            $table->jsonb('must_include')->default('[]');
            $table->jsonb('must_not_include')->default('[]');
            $table->char('checksum', 64);
            $table->foreignId('source_artifact_id')
                ->nullable()
                ->constrained('generation_artifacts')
                ->restrictOnDelete();
            $table->foreignId('based_on_outline_id')->nullable()->constrained('novel_outlines')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['novel_id', 'version']);
            $table->unique('source_artifact_id');
            $table->index(['novel_id', 'status']);
        });

        $grammar = DB::connection()->getSchemaGrammar();
        $outlines = $grammar->wrapTable('novel_outlines');
        $currentIndex = $grammar->wrap('novel_outlines_current_unique');
        DB::statement(
            "CREATE UNIQUE INDEX {$currentIndex} ON {$outlines} (novel_id) WHERE status = 'current'"
        );

        if (DB::getDriverName() === 'pgsql') {
            $statuses = collect(NovelOutlineStatus::cases())
                ->map(fn (NovelOutlineStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');
            $sources = collect(NovelOutlineSource::cases())
                ->map(fn (NovelOutlineSource $source): string => DB::connection()->getPdo()->quote($source->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_version_check CHECK (version > 0)");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_schema_version_check CHECK (schema_version > 0)");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_source_check CHECK (source IN ({$sources}))");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_source_artifact_check CHECK (source <> 'ai' OR source_artifact_id IS NOT NULL)");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_must_include_check CHECK (jsonb_typeof(must_include) = 'array')");
            DB::statement("ALTER TABLE {$outlines} ADD CONSTRAINT novel_outlines_must_not_include_check CHECK (jsonb_typeof(must_not_include) = 'array')");
        }
    }

    /**
     * 删除大纲版本头；仅用于空库开发阶段回滚。
     */
    public function down(): void
    {
        Schema::dropIfExists('novel_outlines');
    }
};
