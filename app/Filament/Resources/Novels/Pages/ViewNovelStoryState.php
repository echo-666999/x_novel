<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;

class ViewNovelStoryState extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '故事状态';

    /** @var array<string, string> */
    private const DOMAINS = [
        'characters' => '人物',
        'relationships' => '关系',
        'locations' => '地点',
        'items' => '物品',
        'world' => '世界',
        'timeline' => '时间线',
        'open_threads' => '开放线索',
        'foreshadowings' => '伏笔',
        'reader_promises' => '读者承诺',
    ];

    #[Url(as: 'version')]
    public ?int $selectedVersion = null;

    public string $activeDomain = 'characters';

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->selectedVersion = $this->resolveSelectedVersion($this->selectedVersion);
    }

    public function getTitle(): string
    {
        return '故事状态检查器';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.story-state-inspector')
                ->viewData(fn (): array => $this->inspectorData()),
        ]);
    }

    public function selectVersion(int $version): void
    {
        $this->selectedVersion = $this->resolveSelectedVersion($version);
    }

    public function selectDomain(string $domain): void
    {
        if (array_key_exists($domain, self::DOMAINS)) {
            $this->activeDomain = $domain;
        }
    }

    /** @return array<string, mixed> */
    private function inspectorData(): array
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();
        $versions = $novel->storyStateVersions()
            ->orderByDesc('version')
            ->get();
        $stateVersion = $versions->firstWhere('version', $this->selectedVersion);
        $activeDomain = array_key_exists($this->activeDomain, self::DOMAINS)
            ? $this->activeDomain
            : 'characters';

        return [
            'novel' => $novel,
            'versions' => $versions,
            'stateVersion' => $stateVersion,
            'domains' => self::DOMAINS,
            'activeDomain' => $activeDomain,
            'domainState' => $stateVersion?->state[$activeDomain] ?? [],
            'isCurrent' => $stateVersion?->is($novel->canonicalStateVersion) ?? false,
        ];
    }

    private function resolveSelectedVersion(?int $requestedVersion): ?int
    {
        /** @var Novel $novel */
        $novel = $this->getRecord();

        if ($requestedVersion !== null && $novel->storyStateVersions()->where('version', $requestedVersion)->exists()) {
            return $requestedVersion;
        }

        return $novel->canonicalStateVersion?->version;
    }
}
