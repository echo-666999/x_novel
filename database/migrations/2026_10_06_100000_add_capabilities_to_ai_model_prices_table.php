<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table): void {
            // 容量必须来自实际模型记录；留空表示尚未核实，不能用于新的 Outline 批次。
            $table->unsignedBigInteger('context_window_tokens')->nullable()->after('billing_unit');
            $table->unsignedBigInteger('max_output_tokens')->nullable()->after('context_window_tokens');
            $table->boolean('supports_structured_output')->default(false)->after('max_output_tokens');
            $table->boolean('supports_reasoning_effort')->default(false)->after('supports_structured_output');
        });
    }

    public function down(): void
    {
        Schema::table('ai_model_prices', function (Blueprint $table): void {
            $table->dropColumn([
                'context_window_tokens',
                'max_output_tokens',
                'supports_structured_output',
                'supports_reasoning_effort',
            ]);
        });
    }
};
