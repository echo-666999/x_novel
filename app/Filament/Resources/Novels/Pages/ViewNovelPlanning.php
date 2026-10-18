<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Enums\StoryArcStatus;
use App\Enums\VolumeStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Models\NovelGenerationTask;
use App\Models\NovelOutline;
use App\Services\ClosureDebtService;
use App\Services\NovelBookWorkflow;
use App\Services\NovelPlanningService;
use App\Services\StoryArcProgressProjector;
use App\Support\GenerationDiagnosticRedactor;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;

class ViewNovelPlanning extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $navigationLabel = '规划';

    #[Url(as: 'scope')]
    public string $scope = 'active';

    #[Locked]
    public int $windowVersion = 0;

    #[Locked]
    public int $taskVersion = 0;

    #[Locked]
    public int $blueprintVersion = 0;

    public string $futureDirection = '';

    public bool $editingDirection = false;

    public string $goalKey = '';

    public string $goalText = '';

    public bool $replanCurrent = false;

    public ?string $feedback = null;

    /** 载入目标时冻结版本；保存失败时保留文本，不能静默套用到另一版。 */
    public function editGoal(string $key): void
    {
        $this->authorizeAccess();
        abort_unless(NovelResource::canEdit($this->getRecord()), 403);
        $novel = $this->getRecord()->fresh();
        $window = NovelOutline::findOrFail($novel->current_goal_window_id);
        $goal = collect($window->sourceArtifact->data['goals'])->firstWhere('key', $key);
        abort_unless($goal, 404);
        $this->windowVersion = $window->id;
        $this->taskVersion = NovelGenerationTask::findOrFail($novel->current_generation_task_id)->control_version;
        $this->goalKey = $key;
        $this->goalText = $goal['objective'];
        $this->replanCurrent = false;
    }

    /** 先展示差异范围再保存方向，不自动修改锁定事实。 */
    public function editDirection(): void
    {
        $this->authorizeAccess();
        abort_unless(NovelResource::canEdit($this->getRecord()), 403);
        $novel = $this->getRecord()->fresh();
        $blueprint = NovelOutline::findOrFail($novel->current_blueprint_id);
        $this->blueprintVersion = $blueprint->id;
        $this->taskVersion = NovelGenerationTask::findOrFail($novel->current_generation_task_id)->control_version;
        $this->futureDirection = $blueprint->sourceArtifact->data['mainline'];
        $this->editingDirection = true;
    }

    public function saveDirection(): void
    {
        $this->authorizeAccess();
        abort_unless(NovelResource::canEdit($this->getRecord()), 403);
        try {
            app(NovelPlanningService::class)->reviseBlueprint($this->getRecord()->current_generation_task_id, $this->taskVersion, $this->blueprintVersion, $this->futureDirection);
            $this->feedback = '未来方向候选已保存。当前章按原计划完成，章节边界重新规划未来目标。';
            $this->editingDirection = false;
        } catch (\Throwable $e) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($e->getMessage());
        }
    }

    public function saveGoal(): void
    {
        $this->authorizeAccess();
        abort_unless(NovelResource::canEdit($this->getRecord()), 403);
        try {
            app(NovelPlanningService::class)->reviseGoal($this->getRecord()->current_generation_task_id, $this->taskVersion, $this->windowVersion, $this->goalKey, $this->goalText, $this->replanCurrent);
            $this->feedback = $this->replanCurrent ? '修订已保存，当前计划将重建，旧稿留档。' : '修订候选已保存，将在章节边界核验后采用。';
            $this->goalKey = '';
            app(NovelBookWorkflow::class)->advance($this->getRecord()->current_generation_task_id);
        } catch (\Throwable $e) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($e->getMessage());
        }
    }

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
        if ($this->getRecord()->current_generation_task_id) {
            return $schema->components([View::make('filament.resources.novels.pages.book-planning')->viewData(['novel' => $this->getRecord()])]);
        }

        return $schema->components([
            View::make('filament.resources.novels.pages.planning-overview')
                ->viewData(fn (): array => [
                    ...$this->planningData(),
                    'scope' => $this->normalizedScope(),
                    'isCompleting' => $this->getRecord()->status->value === 'completing',
                    'closureDebt' => app(ClosureDebtService::class)->calculate($this->getRecord()),
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
                ->with('sourceOutlineArc.beats')
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

        $projector = app(StoryArcProgressProjector::class);
        $volumes->each(fn ($volume) => $volume->storyArcs->each(
            fn ($arc) => $arc->setAttribute('progress_summary', $projector->summary($arc)),
        ));
        $globalArcs->each(fn ($arc) => $arc->setAttribute('progress_summary', $projector->summary($arc)));

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
