<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $artifacts = DB::table('generation_artifacts as artifacts')
            ->join('generation_runs as runs', 'runs.id', '=', 'artifacts.generation_run_id')
            ->where('artifacts.type', 'scene_draft')
            ->whereNotNull('runs.scene_id')
            ->orderBy('runs.scene_id')
            ->orderBy('artifacts.created_at')
            ->orderBy('artifacts.id')
            ->get(['artifacts.id', 'runs.scene_id']);

        $sceneId = null;
        $version = 0;

        foreach ($artifacts as $artifact) {
            if ($sceneId !== $artifact->scene_id) {
                $sceneId = $artifact->scene_id;
                $version = 0;
            }

            DB::table('generation_artifacts')
                ->where('id', $artifact->id)
                ->update(['version' => ++$version]);
        }
    }

    public function down(): void
    {
        DB::table('generation_artifacts')
            ->whereIn('generation_run_id', DB::table('generation_runs')->whereNotNull('scene_id')->select('id'))
            ->where('type', 'scene_draft')
            ->update(['version' => 1]);
    }
};
