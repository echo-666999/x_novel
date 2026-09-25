<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->boolean('error_retryable')->nullable()->after('error_message');
            $table->jsonb('error_metadata')->nullable()->after('error_retryable');
        });
    }

    public function down(): void
    {
        Schema::table('generation_runs', function (Blueprint $table): void {
            $table->dropColumn(['error_retryable', 'error_metadata']);
        });
    }
};
