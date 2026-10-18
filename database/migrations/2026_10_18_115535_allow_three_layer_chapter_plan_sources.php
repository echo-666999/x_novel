<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY = ['novel_outline_id', 'primary_outline_arc_id', 'primary_outline_beat_id', 'primary_outline_milestone_id'];

    /** 新章计划由蓝图、窗口、状态和目标身份约束，不能伪造旧 Arc/Beat 来满足非空列。 */
    public function up(): void
    {
        Schema::table('chapter_plans', function (Blueprint $table): void {
            foreach (self::LEGACY as $column) {
                $table->unsignedBigInteger($column)->nullable()->comment('旧四层规划引用；book-plan-v1不使用，改由蓝图、近期目标及正式状态绑定')->change();
            }
            $table->comment('章节计划：新版三层来源与不可变容量准入，旧规划引用待R07清理');
        });
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('chapter_plans');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT chapter_plans_book_sources_check CHECK (schema_version <> 'book-plan-v1' OR (novel_id IS NOT NULL AND blueprint_id IS NOT NULL AND goal_window_id IS NOT NULL AND source_state_version_id IS NOT NULL AND goal_refs IS NOT NULL AND jsonb_typeof(goal_refs) = 'array' AND jsonb_array_length(goal_refs) > 0))");
        }
    }

    /** 新计划存在时不能恢复旧非空链，拒绝伪造关系进行有损回滚。 */
    public function down(): void
    {
        foreach (self::LEGACY as $column) {
            if (DB::table('chapter_plans')->whereNull($column)->exists()) {
                throw new LogicException('存在三层规划记录，不能无损恢复旧必填引用。');
            }
        }
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('chapter_plans');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT chapter_plans_book_sources_check");
        }
        Schema::table('chapter_plans', function (Blueprint $table): void {
            foreach (self::LEGACY as $column) {
                $table->unsignedBigInteger($column)->nullable(false)->comment('旧章节计划冻结的四层规划引用')->change();
            }
            $table->comment('小说生成系统：chapter_plans，R01来源与执行基础');
        });
    }
};
