<?php

use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use App\Models\StoryStateVersion;
use App\Services\SixRingOutlineMigrationDryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('the six ring outline migration report freezes canonical evidence and chapter twelve lineage without writes', function () {
    $fixture = outlineMigrationFixture();
    $before = migrationDryRunCounts();

    $report = app(SixRingOutlineMigrationDryRun::class)->build($fixture['novel']);

    expect($report['read_only'])->toBeTrue()
        ->and($report['plan_hash'])->toHaveLength(64)
        ->and($report['expected_state_version'])->toBe(11)
        ->and($report['current_outline_id'])->toBeNull()
        ->and($report['chapter_12_artifact_checksum'])->toBe($fixture['chapter_twelve_artifact']->checksum)
        ->and($report['historical_evidence_mapping'])->toHaveCount(9)
        ->and(data_get($report, 'historical_evidence_mapping.0.candidate_status'))->toBe('partial')
        ->and(data_get($report, 'historical_evidence_mapping.0.evidence.0.text'))->toBe('林墨在学院门口第一次向苏璃报上姓名。')
        ->and(data_get($report, 'historical_evidence_mapping.0.automatic_completion'))->toBeFalse()
        ->and($report['missing_canonical_summaries'])->toHaveCount(10)
        ->and($report['chapter_12_options'])->toHaveCount(2)
        ->and($report['professor_candidate']['will_write'])->toBeFalse()
        ->and(collect($report['future_world_entity_candidates'])->every(fn (array $candidate): bool => $candidate['will_write'] === false))->toBeTrue()
        ->and($report['database_writes'])->toBeEmpty()
        ->and($report['provider_calls'])->toBe(0)
        ->and(migrationDryRunCounts())->toBe($before);
});

test('the report refuses any missing or non-canonical source chapter', function () {
    $fixture = outlineMigrationFixture();
    $fixture['chapters'][4]->update(['status' => ChapterStatus::Review]);

    expect(fn () => app(SixRingOutlineMigrationDryRun::class)->build($fixture['novel']))
        ->toThrow(ValidationException::class, 'Chapter 5');
});

test('the command writes reviewable json and markdown artifacts and leaves the database unchanged', function () {
    $fixture = outlineMigrationFixture();
    $before = migrationDryRunCounts();
    $directory = storage_path('framework/testing/out-009-'.fake()->uuid());

    try {
        $this->artisan('novel:outline-migration-dry-run', [
            'novel' => $fixture['novel']->getKey(),
            '--output-dir' => $directory,
        ])->expectsOutputToContain('DRY-RUN：未修改数据库，未调用 AI Provider。')->assertSuccessful();

        $jsonPath = $directory.'/six-ring-afterglow-out-009-dry-run.json';
        $markdownPath = $directory.'/six-ring-afterglow-out-009-dry-run.md';
        $json = json_decode(File::get($jsonPath), true, flags: JSON_THROW_ON_ERROR);
        $markdown = File::get($markdownPath);

        expect(File::exists($jsonPath))->toBeTrue()
            ->and(File::exists($markdownPath))->toBeTrue()
            ->and($json['plan_hash'])->toHaveLength(64)
            ->and($markdown)->toContain('Expected State Version：11')
            ->and($markdown)->toContain('Chapter 12 Artifact Checksum')
            ->and($markdown)->toContain($json['plan_hash'])
            ->and($markdown)->toContain('与苏璃结识')
            ->and(migrationDryRunCounts())->toBe($before);
    } finally {
        File::deleteDirectory($directory);
    }
});

/** @return array{novel: Novel, chapters: array<int, Chapter>, chapter_twelve_artifact: GenerationArtifact} */
function outlineMigrationFixture(): array
{
    $novel = Novel::factory()->create([
        'title' => '六环余光',
        'status' => NovelStatus::Paused,
        'current_chapter_sequence' => 11,
    ]);
    $state = StoryStateVersion::factory()->for($novel)->create(['version' => 11]);
    $novel->update(['canonical_state_version_id' => $state->getKey()]);
    $chapters = [];

    foreach (range(1, 11) as $sequence) {
        $chapter = Chapter::factory()->for($novel)->create([
            'sequence' => $sequence,
            'status' => ChapterStatus::Canonical,
            'summary' => $sequence === 1 ? '林墨与苏璃完成初次交流。' : null,
        ]);
        $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
            'stage' => GenerationStage::ChapterAssembly,
            'status' => RunStatus::Succeeded,
        ]);
        $content = $sequence === 1
            ? '林墨在学院门口第一次向苏璃报上姓名。两人约定次日一起办理入学手续。'
            : "第 {$sequence} 章只推进学院档案线索。";
        $artifact = GenerationArtifact::factory()->for($run)->create([
            'type' => ArtifactType::ChapterDraft,
            'content' => $content,
            'checksum' => hash('sha256', $content),
        ]);
        $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);
        $chapters[] = $chapter->fresh();
    }

    $chapterTwelve = Chapter::factory()->for($novel)->create([
        'sequence' => 12,
        'status' => ChapterStatus::Review,
    ]);
    $chapterTwelveRun = GenerationRun::factory()->for($novel)->for($chapterTwelve)->create([
        'stage' => GenerationStage::ChapterAssembly,
        'status' => RunStatus::Succeeded,
    ]);
    $chapterTwelveArtifact = GenerationArtifact::factory()->for($chapterTwelveRun)->create([
        'type' => ArtifactType::ChapterDraft,
        'content' => '第十二章仍沿用旧学院档案线。',
        'checksum' => hash('sha256', '第十二章仍沿用旧学院档案线。'),
    ]);

    return [
        'novel' => $novel->fresh(),
        'chapters' => $chapters,
        'chapter_twelve_artifact' => $chapterTwelveArtifact,
    ];
}

/** @return array<string, int> */
function migrationDryRunCounts(): array
{
    return [
        'novels' => Novel::query()->count(),
        'chapters' => Chapter::query()->count(),
        'states' => StoryStateVersion::query()->count(),
        'runs' => GenerationRun::query()->count(),
        'artifacts' => GenerationArtifact::query()->count(),
    ];
}
