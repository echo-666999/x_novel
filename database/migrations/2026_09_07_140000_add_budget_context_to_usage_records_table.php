<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->foreignId('novel_id')->nullable()->after('generation_run_id')->constrained()->nullOnDelete();
            $table->foreignId('chapter_id')->nullable()->after('novel_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chapter_id');
            $table->dropConstrainedForeignId('novel_id');
        });
    }
};
