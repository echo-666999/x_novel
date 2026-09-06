<?php

use App\Enums\BibleStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('novel_bibles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('novel_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('logline');
            $table->jsonb('themes')->default('[]');
            $table->string('tone');
            $table->string('pov');
            $table->string('tense');
            $table->jsonb('taboos')->default('[]');
            $table->jsonb('hard_constraints')->default('[]');
            $table->jsonb('ending_contract')->default('{}');
            $table->string('status')->default(BibleStatus::Current->value);
            $table->timestamps();

            $table->unique(['novel_id', 'version']);
            $table->index(['novel_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('novel_bibles');

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT novel_bibles_version_check CHECK (version > 0)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('novel_bibles');
    }
};
