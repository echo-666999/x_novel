<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('outline migrations honor the configured database table prefix in raw sql', function () {
    $originalConnection = DB::getDefaultConnection();
    config()->set('database.connections.outline_prefix_test', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => 'x_',
        'foreign_key_constraints' => true,
    ]);
    DB::purge('outline_prefix_test');
    DB::setDefaultConnection('outline_prefix_test');

    try {
        Schema::create('users', fn (Blueprint $table) => $table->id());
        Schema::create('novels', fn (Blueprint $table) => $table->id());
        Schema::create('generation_artifacts', fn (Blueprint $table) => $table->id());
        Schema::create('volumes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('novel_id');
        });
        Schema::create('story_arcs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('novel_id');
            $table->unsignedBigInteger('volume_id')->nullable();
        });
        Schema::create('chapter_plans', fn (Blueprint $table) => $table->id());
        Schema::create('story_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('novel_id');
            $table->string('event_type');
            $table->string('status');
        });
        Schema::create('chapters', fn (Blueprint $table) => $table->id());
        Schema::create('characters', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('novel_id');
        });

        $createOutlines = require database_path('migrations/2026_09_22_100000_create_novel_outlines_table.php');
        $createOutlines->up();
        $createNormalizedOutlines = require database_path('migrations/2026_09_22_100500_create_normalized_novel_outline_tables.php');
        $createNormalizedOutlines->up();
        $addOutlineFields = require database_path('migrations/2026_09_22_101000_add_novel_outline_fields.php');
        $addOutlineFields->up();

        $tables = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table'"))->pluck('name');
        $indexes = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'index'"))->pluck('name');

        expect($tables)->toContain('x_novel_outlines')
            ->and($tables)->toContain('x_novel_outline_volumes')
            ->and($tables)->toContain('x_novel_outline_arcs')
            ->and($tables)->toContain('x_novel_outline_beats')
            ->and($tables)->toContain('x_novel_outline_milestones')
            ->and($tables)->not->toContain('novel_outlines')
            ->and($indexes)->toContain('novel_outlines_current_unique')
            ->and($indexes)->toContain('outline_volumes_key_unique')
            ->and($indexes)->toContain('outline_arcs_key_unique')
            ->and($indexes)->toContain('story_events_outline_progress_idx')
            ->and(Schema::hasColumn('novels', 'current_outline_id'))->toBeTrue()
            ->and(Schema::hasColumn('chapter_plans', 'primary_outline_milestone_id'))->toBeTrue()
            ->and(Schema::hasColumn('story_events', 'novel_outline_milestone_id'))->toBeTrue();
    } finally {
        DB::disconnect('outline_prefix_test');
        DB::setDefaultConnection($originalConnection);
        DB::purge('outline_prefix_test');
    }
});
