<?php

use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novel_outlines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status')->default(NovelOutlineStatus::Draft->value);
            $table->string('source');
            $table->unsignedInteger('schema_version')->default(1);
            $table->jsonb('content');
            $table->char('checksum', 64);
            $table->foreignId('based_on_outline_id')->nullable()->constrained('novel_outlines')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['novel_id', 'version']);
            $table->index(['novel_id', 'status']);
        });

        DB::statement(
            "CREATE UNIQUE INDEX novel_outlines_current_unique ON novel_outlines (novel_id) WHERE status = 'current'"
        );

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('novel_outlines');
            $statuses = collect(NovelOutlineStatus::cases())
                ->map(fn (NovelOutlineStatus $status): string => DB::connection()->getPdo()->quote($status->value))
                ->implode(', ');
            $sources = collect(NovelOutlineSource::cases())
                ->map(fn (NovelOutlineSource $source): string => DB::connection()->getPdo()->quote($source->value))
                ->implode(', ');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_outlines_version_check CHECK (version > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_outlines_schema_version_check CHECK (schema_version > 0)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_outlines_status_check CHECK (status IN ({$statuses}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_outlines_source_check CHECK (source IN ({$sources}))");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT novel_outlines_content_check CHECK (jsonb_typeof(content) = 'object')");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_outlines');
    }
};
