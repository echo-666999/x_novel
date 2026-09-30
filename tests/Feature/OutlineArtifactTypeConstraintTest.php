<?php

use App\Enums\ArtifactType;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

uses(DatabaseTransactions::class);

function insertOutlineArtifactTypeForConstraint(GenerationRun $run, string $type, int $version): void
{
    DB::table('generation_artifacts')->insert([
        'generation_run_id' => $run->getKey(),
        'type' => $type,
        'version' => $version,
        'content' => '{}',
        'data' => '{}',
        'checksum' => hash('sha256', $type.':'.$version),
        'created_at' => now(),
    ]);
}

test('postgres artifact check allows structure and arc beats and rejects unknown type', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('generation_artifacts CHECK verification requires PostgreSQL.');
    }

    $run = GenerationRun::factory()->create();
    GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::OutlineStructure, 'version' => 1]);
    GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::OutlineArcBeats, 'version' => 1]);

    expect(fn () => DB::transaction(fn () => insertOutlineArtifactTypeForConstraint($run, 'unknown_outline_type', 2)))
        ->toThrow(QueryException::class);
});

test('postgres migration blocks rollback with new artifacts and can rollback and reapply without them', function () {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('migration rollback verification requires PostgreSQL.');
    }

    $migration = require database_path('migrations/2026_09_30_100000_add_outline_structure_and_arc_beats_artifact_types.php');
    $run = GenerationRun::factory()->create();
    $artifact = GenerationArtifact::factory()->for($run)->create(['type' => ArtifactType::OutlineStructure]);

    expect(fn () => $migration->down())->toThrow(RuntimeException::class);

    DB::table('generation_artifacts')->where('id', $artifact->getKey())->delete();
    $migration->down();

    expect(fn () => DB::transaction(fn () => insertOutlineArtifactTypeForConstraint($run, ArtifactType::OutlineStructure->value, 2)))
        ->toThrow(QueryException::class);

    $migration->up();
    insertOutlineArtifactTypeForConstraint($run, ArtifactType::OutlineStructure->value, 2);

    expect(DB::table('generation_artifacts')->where([
        'generation_run_id' => $run->getKey(),
        'type' => ArtifactType::OutlineStructure->value,
        'version' => 2,
    ])->exists())->toBeTrue();
});
