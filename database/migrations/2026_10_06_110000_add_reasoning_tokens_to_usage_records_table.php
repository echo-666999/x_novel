<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('reasoning_tokens')->default(0)->after('output_tokens');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('usage_records');
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT usage_records_reasoning_tokens_check CHECK (reasoning_tokens >= 0)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('usage_records');
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS usage_records_reasoning_tokens_check");
        }

        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn('reasoning_tokens');
        });
    }
};
