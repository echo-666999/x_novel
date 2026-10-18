<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const SCORES = ['score', 'continuity_score', 'plan_score', 'character_score', 'progress_score', 'repetition_score', 'pacing_score', 'style_score'];

    /** 新审校依条目与硬规则得出结论，未评分保存 NULL，不能用虚构零分冒充实际评分。 */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table): void {
            foreach (self::SCORES as $column) {
                $table->decimal($column, 5, 2)->nullable()->comment('可选审校评分，0至100分；NULL表示本合同未评分，不能替代硬门禁')->change();
            }
            $table->comment('小说审校记录：同源条目判断与规则结论，可选评分不覆盖硬冲突');
        });
    }

    /** 有新版未评分记录时拒绝有损降级，避免自动填零破坏历史语义。 */
    public function down(): void
    {
        if (DB::table('reviews')->where(function ($query): void {
            foreach (self::SCORES as $column) {
                $query->orWhereNull($column);
            }
        })->exists()) {
            throw new LogicException('存在未评分审校，不能无损恢复旧必填分数结构。');
        }
        Schema::table('reviews', function (Blueprint $table): void {
            foreach (self::SCORES as $column) {
                $table->decimal($column, 5, 2)->nullable(false)->comment('旧版审校评分，0至100分，硬冲突仍独立阻断')->change();
            }
            $table->comment('小说生成系统：reviews，R01来源与执行基础');
        });
    }
};
