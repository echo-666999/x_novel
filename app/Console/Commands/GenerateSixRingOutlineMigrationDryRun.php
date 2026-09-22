<?php

namespace App\Console\Commands;

use App\Models\Novel;
use App\Services\SixRingOutlineMigrationDryRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Throwable;

class GenerateSixRingOutlineMigrationDryRun extends Command
{
    protected $signature = 'novel:outline-migration-dry-run
        {novel=2 : 六环余光 Novel ID}
        {--output-dir=docs/development/outline-migration-reports : Repository-relative output directory}';

    protected $description = 'Generate read-only JSON and Markdown reports for the Six Ring Afterglow outline migration';

    public function handle(SixRingOutlineMigrationDryRun $dryRun): int
    {
        try {
            $novel = Novel::query()->findOrFail((int) $this->argument('novel'));
            $report = $dryRun->build($novel);
            $directory = $this->outputDirectory((string) $this->option('output-dir'));
            File::ensureDirectoryExists($directory);
            $jsonPath = $directory.'/six-ring-afterglow-out-009-dry-run.json';
            $markdownPath = $directory.'/six-ring-afterglow-out-009-dry-run.md';
            File::put($jsonPath, json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n");
            File::put($markdownPath, $dryRun->markdown($report));

            $this->components->twoColumnDetail('Novel', "{$report['novel']['title']} (#{$report['novel']['id']})");
            $this->components->twoColumnDetail('Expected State Version', (string) $report['expected_state_version']);
            $this->components->twoColumnDetail('Current Outline ID', (string) ($report['current_outline_id'] ?? 'null'));
            $this->components->twoColumnDetail('Chapter 12 Artifact Checksum', (string) ($report['chapter_12_artifact_checksum'] ?? 'null'));
            $this->components->twoColumnDetail('Plan Hash', $report['plan_hash']);
            $this->components->twoColumnDetail('JSON', $jsonPath);
            $this->components->twoColumnDetail('Markdown', $markdownPath);
            $this->warn('DRY-RUN：未修改数据库，未调用 AI Provider。');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('OUT-009 Dry Run 失败：'.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function outputDirectory(string $path): string
    {
        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return base_path(trim($path, DIRECTORY_SEPARATOR));
    }
}
