<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_routes', function (Blueprint $table): void {
            $table->string('reasoning_effort', 16)->nullable()->after('model');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::getTablePrefix().'ai_model_routes';
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT ai_model_routes_reasoning_effort_check CHECK (reasoning_effort IS NULL OR reasoning_effort IN ('low', 'medium', 'high'))");
        }

        // 全书大纲原本固定使用 low；迁移到模型路由后保持现有生产行为。
        DB::table('ai_model_routes')->where('role', 'planner')->update(['reasoning_effort' => 'low']);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::getTablePrefix().'ai_model_routes';
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS ai_model_routes_reasoning_effort_check");
        }

        Schema::table('ai_model_routes', function (Blueprint $table): void {
            $table->dropColumn('reasoning_effort');
        });
    }
};
