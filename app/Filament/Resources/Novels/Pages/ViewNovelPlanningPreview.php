<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Data\PlanValidationResult;
use App\Enums\ForeshadowingPlanAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\ChapterPlan;
use App\Services\PlanValidator;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewNovelPlanningPreview extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static ?string $navigationLabel = '规划预览';

    public int $chapterId;

    protected ?ChapterPlan $cachedPlan = null;

    /** @var array<string, mixed>|null */
    protected ?array $cachedOutlineTarget = null;

    protected bool $outlineTargetResolved = false;

    public function mount(int|string $record, int|string|null $chapter = null): void
    {
        parent::mount($record);

        $chapterId = $this->getRecord()->chapters()
            ->whereKey($chapter)
            ->value('id');

        abort_if($chapterId === null, 404);

        $this->chapterId = (int) $chapterId;
    }

    public function getTitle(): string
    {
        return '规划预览';
    }

    public function getSubheading(): ?string
    {
        $chapter = $this->chapterPlan()?->chapter
            ?? $this->getRecord()->chapters()->find($this->chapterId);

        return $chapter === null
            ? $this->getRecord()->title
            : $this->getRecord()->title." · 第 {$chapter->sequence} 章 · {$chapter->title}";
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToChapter')
                ->label('返回章节工作台')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn (): string => NovelResource::getUrl('chapters', [
                    'record' => $this->getRecord(),
                ])),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('尚未建立章节计划')
                ->description('返回章节列表建立完整计划后，才能进行生成前预览。')
                ->icon('heroicon-o-clipboard-document-list')
                ->visible(fn (): bool => $this->chapterPlan() === null),
            Section::make('本章为什么存在')
                ->description('生成正文前先确认章节目标、故事线推进和对读者的承诺。')
                ->columns(['default' => 1, 'lg' => 3])
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    TextEntry::make('chapter_function')
                        ->label('章节功能')
                        ->state(fn (): ?string => $this->chapterPlan()?->chapter_function),
                    TextEntry::make('arc_contribution')
                        ->label('故事线贡献')
                        ->state(fn (): ?string => $this->chapterPlan()?->arc_contribution),
                    TextEntry::make('reader_promise')
                        ->label('读者承诺')
                        ->state(fn (): ?string => $this->chapterPlan()?->reader_promise),
                ]),
            Section::make('执行基线')
                ->columns(['default' => 1, 'md' => 3, 'xl' => 6])
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    TextEntry::make('plan_version')
                        ->label('计划版本')
                        ->state(fn (): ?string => $this->chapterPlan() === null ? null : 'v'.$this->chapterPlan()->version),
                    TextEntry::make('plan_status')
                        ->label('计划状态')
                        ->state(fn () => $this->chapterPlan()?->status)
                        ->badge(),
                    TextEntry::make('validation_status')
                        ->label('检查结果')
                        ->state(fn () => $this->validationResult()?->status())
                        ->badge(),
                    TextEntry::make('target_words')
                        ->label('目标字数')
                        ->state(fn (): ?int => $this->chapterPlan()?->target_words)
                        ->numeric(),
                    TextEntry::make('pov')
                        ->label('视角角色')
                        ->state(fn (): ?string => $this->chapterPlan()?->povCharacter?->name),
                    TextEntry::make('tone')
                        ->label('语气 / 钩子')
                        ->state(fn (): ?string => $this->chapterPlan() === null
                            ? null
                            : $this->chapterPlan()->tone.' · '.$this->chapterPlan()->hook_type),
                ]),
            Section::make('Current Outline Target')
                ->description('本章计划冻结的 Outline Version、Primary Beat 与章节预算。')
                ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                ->visible(fn (): bool => $this->outlineTarget() !== null)
                ->schema([
                    TextEntry::make('outline_version')
                        ->label('Outline Version')
                        ->state(fn (): ?string => $this->chapterPlan()?->novelOutline === null ? null : 'v'.$this->chapterPlan()->novelOutline->version),
                    TextEntry::make('outline_checksum')
                        ->label('Checksum')
                        ->state(fn (): ?string => $this->chapterPlan()?->novelOutline?->checksum)
                        ->fontFamily('mono')
                        ->copyable(),
                    TextEntry::make('primary_beat')
                        ->label('Primary Beat')
                        ->state(fn (): ?string => data_get($this->outlineTarget(), 'beat.title')),
                    TextEntry::make('primary_beat_key')
                        ->label('Beat Key')
                        ->state(fn (): ?string => data_get($this->outlineTarget(), 'beat.key'))
                        ->fontFamily('mono'),
                    TextEntry::make('chapter_budget')
                        ->label('章节预算')
                        ->state(function (): ?string {
                            $budget = data_get($this->outlineTarget(), 'beat.chapter_budget');

                            return is_array($budget) ? ($budget['min'].'–'.($budget['max'] ?? '∞').' 章') : null;
                        }),
                    TextEntry::make('acceptance_criteria')
                        ->label('验收条件')
                        ->state(fn (): array => data_get($this->outlineTarget(), 'beat.acceptance_criteria', []))
                        ->bulleted()
                        ->columnSpanFull(),
                    TextEntry::make('outline_must_include')
                        ->label('必须包含')
                        ->state(fn (): array => data_get($this->outlineTarget(), 'beat.must_include', []))
                        ->bulleted(),
                    TextEntry::make('outline_must_not_include')
                        ->label('禁止包含')
                        ->state(fn (): array => data_get($this->outlineTarget(), 'beat.must_not_include', []))
                        ->bulleted(),
                ]),
            Section::make('场景流程')
                ->description('按计划顺序检查每个场景的目标、冲突、转折和结果。')
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    RepeatableEntry::make('scene_flow')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->sceneFlow())
                        ->columns(['default' => 1, 'md' => 2, 'xl' => 4])
                        ->schema([
                            TextEntry::make('sequence')
                                ->label('场景')
                                ->badge(),
                            TextEntry::make('pov')
                                ->label('视角角色')
                                ->placeholder('继承章节计划'),
                            TextEntry::make('location')
                                ->label('地点')
                                ->placeholder('未指定'),
                            TextEntry::make('time_anchor')
                                ->label('时间锚点')
                                ->placeholder('继承章节计划'),
                            TextEntry::make('transition_from_previous')
                                ->label('衔接安排')
                                ->placeholder('未指定')
                                ->columnSpanFull(),
                            TextEntry::make('goal')->label('目标'),
                            TextEntry::make('conflict')->label('冲突'),
                            TextEntry::make('turn')->label('转折'),
                            TextEntry::make('outcome')->label('结果'),
                            TextEntry::make('outcome_allowed')
                                ->label('结果允许行为')
                                ->badge()
                                ->placeholder('未限定'),
                            TextEntry::make('outcome_forbidden')
                                ->label('结果禁止行为')
                                ->badge()
                                ->placeholder('未限定'),
                            TextEntry::make('continuity_requirements')
                                ->label('跨场景连续性契约')
                                ->bulleted()
                                ->placeholder('无')
                                ->columnSpanFull(),
                        ]),
                ]),
            Section::make('约束与伏笔')
                ->description('这些内容在生成上下文中具有高优先级。')
                ->columns(['default' => 1, 'lg' => 2])
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    TextEntry::make('due_foreshadowings')
                        ->label('伏笔动作')
                        ->state(fn (): array => $this->dueForeshadowings())
                        ->bulleted()
                        ->placeholder('无'),
                    TextEntry::make('required_facts')
                        ->label('必需事实')
                        ->state(fn (): array => $this->requiredFacts())
                        ->bulleted()
                        ->placeholder('无'),
                    TextEntry::make('forbidden_conflicts')
                        ->label('禁止冲突')
                        ->state(fn (): array => $this->chapterPlan()?->forbidden_conflicts ?? [])
                        ->bulleted()
                        ->placeholder('无'),
                    TextEntry::make('must_not_reveal')
                        ->label('禁止揭示')
                        ->state(fn (): array => $this->chapterPlan()?->must_not_reveal ?? [])
                        ->bulleted()
                        ->placeholder('无'),
                ]),
            Section::make('计划检查结果')
                ->description('已阻塞的问题必须修复后才能进入生成；警告项需要人工确认。')
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    RepeatableEntry::make('findings')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->findings())
                        ->columns(['default' => 1, 'md' => 3])
                        ->schema([
                            TextEntry::make('severity')->label('级别')->badge(),
                            TextEntry::make('code')->label('规则'),
                            TextEntry::make('message')->label('说明')->columnSpan(2),
                        ])
                        ->placeholder('未发现阻塞或警告。'),
                ]),
        ]);
    }

    private function chapterPlan(): ?ChapterPlan
    {
        return $this->cachedPlan ??= ChapterPlan::query()
            ->whereHas('chapter', fn ($query) => $query
                ->whereKey($this->chapterId)
                ->where('novel_id', $this->getRecord()->getKey()))
            ->with(['chapter', 'povCharacter', 'novelOutline'])
            ->orderByDesc('version')
            ->first();
    }

    /** @return array<string, mixed>|null */
    private function outlineTarget(): ?array
    {
        if ($this->outlineTargetResolved) {
            return $this->cachedOutlineTarget;
        }
        $this->outlineTargetResolved = true;
        $plan = $this->chapterPlan();
        $outline = $plan?->novelOutline;
        $primary = collect($plan?->arc_contributions ?? [])
            ->first(fn (mixed $item): bool => is_array($item) && ($item['role'] ?? null) === 'primary');

        if ($outline === null || ! is_array($primary)) {
            return null;
        }

        $outlineKey = $this->getRecord()->storyArcs()->whereKey($primary['arc_id'] ?? null)->value('outline_key');

        foreach ($outline->content['volumes'] ?? [] as $volume) {
            foreach ($volume['arcs'] ?? [] as $arc) {
                if (($arc['key'] ?? null) !== $outlineKey) {
                    continue;
                }
                foreach ($arc['beats'] ?? [] as $beat) {
                    if (($beat['key'] ?? null) === ($primary['beat_key'] ?? null)) {
                        return $this->cachedOutlineTarget = ['volume' => $volume, 'arc' => $arc, 'beat' => $beat];
                    }
                }
            }
        }

        return null;
    }

    private function validationResult(): ?PlanValidationResult
    {
        return $this->chapterPlan() === null
            ? null
            : app(PlanValidator::class)->validate($this->chapterPlan());
    }

    /** @return array<int, array<string, mixed>> */
    private function sceneFlow(): array
    {
        $plan = $this->chapterPlan();

        if ($plan === null) {
            return [];
        }

        $characterNames = $this->getRecord()->characters()->pluck('name', 'id');

        return collect($plan->scene_plans ?? [])
            ->values()
            ->map(fn (array $scene, int $index): array => [
                'sequence' => '场景 '.($index + 1),
                'pov' => $characterNames->get($scene['pov_character_id'] ?? $plan->pov_character_id),
                'location' => $scene['location'] ?? null,
                'time_anchor' => $scene['time_anchor'] ?? $plan->time_anchor,
                'transition_from_previous' => $scene['transition_from_previous'] ?? null,
                'goal' => $scene['goal'] ?? null,
                'conflict' => $scene['conflict'] ?? null,
                'turn' => $scene['turn'] ?? null,
                'outcome' => $scene['outcome'] ?? null,
                'outcome_allowed' => $scene['outcome_allowed'] ?? [],
                'outcome_forbidden' => $scene['outcome_forbidden'] ?? [],
                'continuity_requirements' => collect($scene['continuity_requirements'] ?? [])
                    ->filter(fn (mixed $requirement): bool => is_array($requirement))
                    ->map(fn (array $requirement): string => sprintf(
                        '%s · %s · %s',
                        (string) ($requirement['key'] ?? '未命名'),
                        match ($requirement['mode'] ?? null) {
                            'establish' => '首次建立',
                            'persist' => '持续状态',
                            'change' => '状态变化',
                            'callback' => '章末回扣',
                            default => '未知模式',
                        },
                        (string) ($requirement['description'] ?? ''),
                    ))
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function dueForeshadowings(): array
    {
        $plan = $this->chapterPlan();
        if ($plan === null) {
            return [];
        }

        $foreshadowings = $this->getRecord()->foreshadowings()
            ->whereKey($plan->referencedForeshadowingIds())
            ->get()
            ->keyBy('id');
        $actions = collect($plan->foreshadowingActionContracts())
            ->map(function (array $contract) use ($foreshadowings): string {
                $foreshadowing = $foreshadowings->get((int) ($contract['foreshadowing_id'] ?? 0));
                $action = ForeshadowingPlanAction::tryFrom((string) ($contract['action'] ?? ''));
                $title = $foreshadowing?->title ?? '未知伏笔 #'.($contract['foreshadowing_id'] ?? '?');

                return $title.' · '.($action?->getLabel() ?? '未知动作')
                    .' · Scene '.($contract['target_scene_sequence'] ?? '?')
                    .' · '.($contract['acceptance_criteria'] ?? '未填写验收条件');
            });
        $legacy = collect($plan->legacyForeshadowingIds())
            ->map(fn (int $id): string => ($foreshadowings->get($id)?->title ?? "未知伏笔 #{$id}").' · 旧 ID 引用（待转换动作契约）');

        return $actions->concat($legacy)->values()->all();
    }

    /** @return array<int, string> */
    private function requiredFacts(): array
    {
        $ids = array_map('intval', $this->chapterPlan()?->required_facts ?? []);

        return $this->getRecord()->facts()
            ->whereKey($ids)
            ->with(['characterSubject', 'worldEntitySubject'])
            ->get()
            ->map(fn ($fact): string => $fact->subjectLabel().' · '.$fact->predicate.' · '.$fact->valueSummary())
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function findings(): array
    {
        return collect($this->validationResult()?->findings ?? [])
            ->map(fn ($finding): array => [
                'severity' => $finding->severity,
                'code' => $finding->code,
                'message' => $finding->message,
            ])
            ->all();
    }
}
