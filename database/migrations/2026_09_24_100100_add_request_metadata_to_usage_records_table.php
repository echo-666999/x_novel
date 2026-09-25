<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->jsonb('request_metadata')->nullable()->after('request_id');
        });
    }

    public function down(): void
    {
        Schema::table('usage_records', function (Blueprint $table): void {
            $table->dropColumn('request_metadata');
        });
    }
};
