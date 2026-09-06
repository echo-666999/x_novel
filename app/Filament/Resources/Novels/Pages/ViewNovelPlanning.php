<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

class ViewNovelPlanning extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '规划';

    #[Url(as: 'scope')]
    public string $scope = 'active';

    public function getTitle(): string
    {
        return '规划总览';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.planning-overview')
                ->viewData(fn (): array => [
                    ...$this->planningData(),
                    'scope' => $this->normalizedScope(),
                ]),
        ]);
    }

    public function setScope(string $scope): void
    {
        if (! in_array($scope, ['active', 'completed', 'all'], true)) {
            return;
        }

        $this->scope = $scope;
    }

    /** @return array<string, mixed> */
    private function planningData(): array
    {
        $scope = $this->normalizedScope();
        $arcStatus = match ($scope) {
            'active' => StoryArcStatus::Active,
            'completed' => StoryArcStatus::Completed,
            default => null,
        };
        $volumeStatus = match ($scope) {
            'active' => VolumeStatus::Active,
            'completed' => VolumeStatus::Completed,
            default => null,
        };

        /** @var Novel $novel */
        $novel = $this->getRecord();

        $volumes = $novel->volumes()
            ->with(['storyArcs' => fn ($query) => $query
                ->when($arcStatus, fn ($query) => $query->where('status', $arcStatus))
                ->orderByRaw("CASE WHEN type = 'main' THEN 0 ELSE 1 END")
                ->orderBy('title')])
            ->when(
                $arcStatus,
                fn ($query) => $query->where(
                    fn ($query) => $query
                        ->where('status', $volumeStatus)
                        ->orWhereHas('storyArcs', fn ($query) => $query->where('status', $arcStatus)),
                ),
            )
            ->get();

        $globalArcs = $novel->storyArcs()
            ->whereNull('volume_id')
            ->when($arcStatus, fn ($query) => $query->where('status', $arcStatus))
            ->orderByRaw("CASE WHEN type = 'main' THEN 0 ELSE 1 END")
            ->orderBy('title')
            ->get();

        return [
            'volumes' => $volumes,
            'globalArcs' => $globalArcs,
            'arcCount' => $volumes->sum(fn ($volume): int => $volume->storyArcs->count()) + $globalArcs->count(),
        ];
    }

    private function normalizedScope(): string
    {
        return in_array($this->scope, ['active', 'completed', 'all'], true)
            ? $this->scope
            : 'active';
    }
}
