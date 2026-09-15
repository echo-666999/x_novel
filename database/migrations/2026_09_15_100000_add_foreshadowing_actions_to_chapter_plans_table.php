<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->jsonb('foreshadowing_actions')->default('[]')->after('due_foreshadowings');
        });
    }

    public function down(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table) {
            $table->dropColumn('foreshadowing_actions');
        });
    }
};
