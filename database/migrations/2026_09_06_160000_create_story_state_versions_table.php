<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_state_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->jsonb('state');
            $table->char('checksum', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['novel_id', 'version']);
            $table->index('chapter_id');
            $table->index('checksum');
        });

        Schema::table('novels', function (Blueprint $table) {
            $table->foreignId('canonical_state_version_id')
                ->nullable()
                ->constrained('story_state_versions')
                ->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('story_state_versions');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT story_state_versions_version_check CHECK (version >= 0)");
        }
    }

    public function down(): void
    {
        Schema::table('novels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('canonical_state_version_id');
        });

        Schema::dropIfExists('story_state_versions');
    }
};
