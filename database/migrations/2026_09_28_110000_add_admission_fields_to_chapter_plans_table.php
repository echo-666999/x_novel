<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->string('checksum', 64)->nullable();
            $table->string('input_hash', 64)->nullable();
            $table->jsonb('admission_snapshot')->nullable();
            $table->timestamp('admitted_at')->nullable();

            $table->index(['chapter_id', 'input_hash'], 'chapter_plans_admission_input_idx');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->dropIndex('chapter_plans_admission_input_idx');
            $table->dropColumn(['checksum', 'input_hash', 'admission_snapshot', 'admitted_at']);
        });
    }
};
