<?php

use App\Enums\NovelStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('novels', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('genre');
            $table->text('premise')->nullable();
            $table->unsignedBigInteger('target_words');
            $table->string('status')->default(NovelStatus::Draft->value)->index();
            $table->unsignedInteger('current_chapter_sequence')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestamps();

            $table->index('updated_at');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('novels');

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT novels_target_words_check CHECK (target_words > 0)"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('novels');
    }
};
