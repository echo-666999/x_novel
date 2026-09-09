<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Data\PlanValidationResult;
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
                            TextEntry::make('goal')->label('目标'),
                            TextEntry::make('conflict')->label('冲突'),
                            TextEntry::make('turn')->label('转折'),
                            TextEntry::make('outcome')->label('结果'),
                        ]),
                ]),
            Section::make('约束与伏笔')
                ->description('这些内容在生成上下文中具有高优先级。')
                ->columns(['default' => 1, 'lg' => 2])
                ->visible(fn (): bool => $this->chapterPlan() !== null)
                ->schema([
                    TextEntry::make('due_foreshadowings')
                        ->label('到期伏笔')
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
            ->with(['chapter', 'povCharacter'])
            ->orderByDesc('version')
            ->first();
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
                'goal' => $scene['goal'] ?? null,
                'conflict' => $scene['conflict'] ?? null,
                'turn' => $scene['turn'] ?? null,
                'outcome' => $scene['outcome'] ?? null,
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function dueForeshadowings(): array
    {
        $ids = array_map('intval', $this->chapterPlan()?->due_foreshadowings ?? []);

        return $this->getRecord()->foreshadowings()
            ->whereKey($ids)
            ->get()
            ->map(fn ($foreshadowing): string => $foreshadowing->title.' · '.$foreshadowing->importance->getLabel().' · '.$foreshadowing->status->getLabel())
            ->all();
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
