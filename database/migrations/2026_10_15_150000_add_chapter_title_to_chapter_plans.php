<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table): void {
            // 历史计划没有章名；新计划在生成或人工修订时提供，不改写冻结的旧版本。
            $table->string('chapter_title', 80)->nullable()->after('chapter_function');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table): void {
            $table->dropColumn('chapter_title');
        });
    }
};
