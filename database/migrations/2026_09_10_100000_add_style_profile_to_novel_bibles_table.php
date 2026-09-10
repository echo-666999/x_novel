<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('novel_bibles', function (Blueprint $table) {
            $table->jsonb('style_profile')->nullable()->after('ending_contract');
        });

        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('novel_bibles');

            DB::statement(
                "ALTER TABLE {$table} ADD CONSTRAINT novel_bibles_style_profile_object_check CHECK (style_profile IS NULL OR jsonb_typeof(style_profile) = 'object')"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $table = DB::connection()->getSchemaGrammar()->wrapTable('novel_bibles');

            DB::statement(
                "ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS novel_bibles_style_profile_object_check"
            );
        }

        Schema::table('novel_bibles', function (Blueprint $table) {
            $table->dropColumn('style_profile');
        });
    }
};
