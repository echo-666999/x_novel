<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_records', function (Blueprint $table) {
            $table->id();
            // TASK-060 creates generation_runs; the foreign key is added with that table.
            $table->unsignedBigInteger('generation_run_id')->nullable()->index();
            $table->string('provider');
            $table->string('model');
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->unsignedBigInteger('cached_tokens')->default(0);
            $table->unsignedInteger('latency_ms');
            $table->decimal('estimated_cost', 14, 6)->default(0);
            $table->string('request_id')->nullable()->index();
            $table->timestamp('created_at')->useCurrent()->index();

            $table->index(['provider', 'model']);
            $table->unique(['provider', 'request_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('usage_records');

            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT usage_records_tokens_check CHECK (input_tokens >= 0 AND output_tokens >= 0 AND cached_tokens >= 0 AND cached_tokens <= input_tokens)");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT usage_records_metrics_check CHECK (latency_ms >= 0 AND estimated_cost >= 0)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_records');
    }
};
