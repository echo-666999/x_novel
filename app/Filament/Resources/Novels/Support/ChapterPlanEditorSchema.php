<?php

namespace App\Filament\Resources\Novels\Support;

use App\Enums\FactStatus;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingTimingStatus;
use App\Enums\PlanStatus;
use App\Models\Chapter;
use App\Models\Foreshadowing;
use App\Models\Novel;
use App\Services\ForeshadowingLifecycleResolver;
use App\Services\NovelOutlineLengthContract;
use App\Services\PlanValidator;
use Closure;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

final class ChapterPlanEditorSchema
{
    /**
     * @param  Closure(): (?Chapter)  $resolveChapter
     * @return array<int, mixed>
     */
    public function schema(Novel $novel, Closure $resolveChapter): array
    {
        return [
            ...$this->planFindingsSchema($resolveChapter),
            Section::make('章节目标')
                ->description('说明本章为什么存在、推进哪条故事线，以及向读者兑现什么。')
                ->columns(['default' => 1, 'md' => 2])
                ->schema([
                    Textarea::make('chapter_function')
                        ->label('章节功能')
                        ->rows(3)
                        ->required(),
                    Textarea::make('arc_contribution')
                        ->label('故事线贡献')
                        ->rows(3)
                        ->required(),
                    Textarea::make('reader_promise')
                        ->label('读者承诺')
                        ->rows(3)
                        ->required()
                        ->columnSpanFull(),
                ]),
            Section::make('叙事参数')
                ->columns(['default' => 1, 'md' => 3])
                ->schema([
                    TextInput::make('target_words')
                        ->label('目标字数')
                        ->helperText('由 Current Outline 字数合同决定；如需调整，请重新生成或修订全书大纲合同。')
                        ->integer()
                        ->minValue(1)
                        ->default(fn (): int => $this->chapterTargetWords($novel))
                        ->readOnly()
                        ->required(),
                    Select::make('pov_character_id')
                        ->label('POV 角色')
                        ->options(fn (): array => $novel->characters()->orderBy('name')->pluck('name', 'id')->all())
                        ->searchable()
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->label('Plan 状态')
                        ->options(PlanStatus::class)
                        ->default(PlanStatus::Draft)
                        ->required(),
                    TextInput::make('tone')
                        ->label('语气')
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('time_anchor')
                        ->label('时间锚点')
                        ->maxLength(255)
                        ->required(),
                    TextInput::make('hook_type')
                        ->label('钩子类型')
                        ->maxLength(255)
                        ->required(),
                ]),
            Section::make('内容边界')
                ->description('明确必须披露、允许暗示和本章禁止提前揭示的内容。')
                ->columns(['default' => 1, 'lg' => 2])
                ->schema([
                    TagsInput::make('must_reveal')
                        ->label('必须揭示')
                        ->default([]),
                    TagsInput::make('may_hint')
                        ->label('可以暗示')
                        ->default([]),
                    TagsInput::make('must_not_reveal')
                        ->label('禁止揭示')
                        ->default([]),
                    TagsInput::make('forbidden_conflicts')
                        ->label('禁止冲突')
                        ->default([]),
                    Select::make('required_facts')
                        ->label('必需事实')
                        ->options(fn (): array => $this->factOptions($novel))
                        ->multiple()
                        ->searchable()
                        ->preload(),
                    Repeater::make('foreshadowing_actions')
                        ->label('伏笔动作契约')
                        ->helperText('延期或放弃由当前登录用户明确授权，必须填写原因；延期还必须填写晚于原窗口的新兑现窗口。')
                        ->columns(['default' => 1, 'lg' => 2])
                        ->schema([
                            Select::make('foreshadowing_id')
                                ->label('伏笔')
                                ->options(fn (): array => $this->dueForeshadowingOptions($novel, $resolveChapter()))
                                ->searchable()
                                ->preload()
                                ->required(),
                            Select::make('action')
                                ->label('动作')
                                ->options(ForeshadowingPlanAction::options())
                                ->required(),
                            TextInput::make('target_scene_sequence')
                                ->label('目标 Scene 序号')
                                ->integer()
                                ->minValue(1)
                                ->required(),
                            Textarea::make('acceptance_criteria')
                                ->label('正文验收条件')
                                ->rows(2)
                                ->required(),
                            Textarea::make('reason')
                                ->label('延期/放弃原因')
                                ->rows(2)
                                ->helperText('plant、reinforce、pay_off 可留空。'),
                            TextInput::make('new_due_from_chapter')
                                ->label('延期后窗口开始章')
                                ->integer()
                                ->minValue(1),
                            TextInput::make('new_due_to_chapter')
                                ->label('延期后窗口结束章')
                                ->integer()
                                ->minValue(1),
                        ])
                        ->default([])
                        ->columnSpanFull(),
                ]),
            Section::make('场景计划')
                ->description('按正文顺序拆分场景。每个场景都必须产生明确转折和结果。')
                ->schema([
                    Repeater::make('scene_plans')
                        ->label('Scenes')
                        ->schema([
                            Select::make('pov_character_id')
                                ->label('POV 角色')
                                ->options(fn (): array => $novel->characters()->orderBy('name')->pluck('name', 'id')->all())
                                ->searchable()
                                ->preload()
                                ->placeholder('继承 Chapter Plan POV'),
                            TextInput::make('location')
                                ->label('地点')
                                ->maxLength(255),
                            TextInput::make('time_anchor')
                                ->label('时间锚点')
                                ->maxLength(255)
                                ->placeholder('继承 Chapter Plan 时间锚点'),
                            Textarea::make('transition_from_previous')
                                ->label('与上一段的衔接')
                                ->helperText('第一场景说明如何承接上一章正式结尾；后续场景说明如何承接前一场景。')
                                ->rows(2),
                            Textarea::make('goal')->label('目标')->rows(2)->required(),
                            Textarea::make('conflict')->label('冲突')->rows(2)->required(),
                            Textarea::make('turn')->label('转折')->rows(2)->required(),
                            Textarea::make('outcome')->label('结果')->rows(2)->required(),
                            TagsInput::make('outcome_allowed')
                                ->label('结果允许行为')
                                ->helperText('列出符合该结果的具体行为；没有时留空。')
                                ->default([]),
                            TagsInput::make('outcome_forbidden')
                                ->label('结果禁止行为')
                                ->helperText('列出会反转或越过该结果的具体行为；没有时留空。')
                                ->default([]),
                            Repeater::make('continuity_requirements')
                                ->label('跨场景连续性契约')
                                ->helperText('使用稳定 key 表达同一持续状态；首次建立、后续增量、真实变化和章末回扣分别选择对应模式。')
                                ->schema([
                                    TextInput::make('key')
                                        ->label('状态 Key')
                                        ->required(),
                                    Select::make('mode')
                                        ->label('模式')
                                        ->options([
                                            'establish' => '首次建立',
                                            'persist' => '持续状态',
                                            'change' => '状态变化',
                                            'callback' => '章末回扣',
                                        ])
                                        ->required(),
                                    Textarea::make('description')
                                        ->label('本场要求')
                                        ->rows(2)
                                        ->required(),
                                ])
                                ->columns(['default' => 1, 'lg' => 3])
                                ->default([])
                                ->columnSpanFull(),
                        ])
                        ->columns(['default' => 1, 'lg' => 2])
                        ->minItems(1)
                        ->defaultItems(1)
                        ->reorderable()
                        ->addActionLabel('添加场景')
                        ->required(),
                ]),
        ];
    }

    /** @return array<string, mixed> */
    public function formData(Chapter $chapter): array
    {
        $plan = $chapter->latestPlan;

        if ($plan === null) {
            return [
                'target_words' => $this->chapterTargetWords($chapter->novel),
                'must_reveal' => [],
                'may_hint' => [],
                'must_not_reveal' => [],
                'required_facts' => [],
                'forbidden_conflicts' => [],
                'due_foreshadowings' => [],
                'foreshadowing_actions' => [],
                'scene_plans' => [[]],
                'status' => PlanStatus::Draft->value,
            ];
        }

        return [
            ...$plan->only([
                'chapter_function',
                'arc_contribution',
                'reader_promise',
                'target_words',
                'pov_character_id',
                'tone',
                'time_anchor',
                'hook_type',
                'must_reveal',
                'may_hint',
                'must_not_reveal',
                'required_facts',
                'forbidden_conflicts',
                'due_foreshadowings',
                'foreshadowing_actions',
                'scene_plans',
                'status',
            ]),
            'target_words' => $this->chapterTargetWords($chapter->novel),
        ];
    }

    private function chapterTargetWords(Novel $novel): int
    {
        if ($novel->current_outline_id === null) {
            return (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000);
        }

        return (int) app(NovelOutlineLengthContract::class)->forNovel($novel)['chapter_target_words'];
    }

    /**
     * @param  Closure(): (?Chapter)  $resolveChapter
     * @return array<int, mixed>
     */
    private function planFindingsSchema(Closure $resolveChapter): array
    {
        return [
            Section::make('Plan Findings')
                ->description('Blocked 必须修复后才能进入生成；Warning 需要人工确认。')
                ->schema([
                    TextEntry::make('validation_status')
                        ->label('校验状态')
                        ->state(function () use ($resolveChapter): string {
                            $chapter = $resolveChapter();

                            return $chapter?->latestPlan === null
                                ? '尚未保存 Plan'
                                : app(PlanValidator::class)->validate($chapter->latestPlan)->status()->getLabel();
                        }),
                    TextEntry::make('validation_findings')
                        ->label('Findings')
                        ->state(function () use ($resolveChapter): HtmlString|string {
                            $chapter = $resolveChapter();
                            if ($chapter?->latestPlan === null) {
                                return '保存 Plan 后显示检查结果。';
                            }

                            $result = app(PlanValidator::class)->validate($chapter->latestPlan);

                            if ($result->findings === []) {
                                return '未发现阻塞或警告。';
                            }

                            return new HtmlString(collect($result->findings)
                                ->map(fn ($finding): string => '<strong>'.e($finding->severity->getLabel()).'</strong> · <code>'.e($finding->code).'</code><br>'.e($finding->message))
                                ->implode('<br><br>'));
                        }),
                ]),
        ];
    }

    /** @return array<int, string> */
    private function factOptions(Novel $novel): array
    {
        return $novel->facts()
            ->where('status', FactStatus::Active)
            ->with(['characterSubject', 'worldEntitySubject'])
            ->get()
            ->mapWithKeys(fn ($fact): array => [
                $fact->getKey() => $fact->subjectLabel().' · '.$fact->predicate.' · '.$fact->valueSummary(),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private function dueForeshadowingOptions(Novel $novel, ?Chapter $chapter): array
    {
        if ($chapter === null) {
            return [];
        }

        $targetChapter = Foreshadowing::nextChapterSequence($novel->current_chapter_sequence);
        $selectedIds = $chapter->latestPlan?->referencedForeshadowingIds() ?? [];
        $lifecycleResolver = app(ForeshadowingLifecycleResolver::class);
        $novel->loadMissing('canonicalStateVersion');

        return $novel->foreshadowings()
            ->where(function (Builder $query) use ($selectedIds, $targetChapter): void {
                $query->where('due_from_chapter', '<=', $targetChapter);

                if ($selectedIds !== []) {
                    $query->orWhereIn('id', $selectedIds);
                }
            })
            ->orderBy('due_to_chapter')
            ->get()
            ->reject(fn (Foreshadowing $foreshadowing): bool => ! in_array($foreshadowing->getKey(), $selectedIds, true)
                && $lifecycleResolver->status($foreshadowing, $novel)->isTerminal())
            ->mapWithKeys(function (Foreshadowing $foreshadowing) use ($lifecycleResolver, $novel, $targetChapter): array {
                $status = $lifecycleResolver->status($foreshadowing, $novel);
                $timing = ForeshadowingTimingStatus::forTargetChapter(
                    $status,
                    $foreshadowing->due_from_chapter,
                    $foreshadowing->due_to_chapter,
                    $targetChapter,
                );
                $timingLabel = $timing?->getLabel() ?? '已结束';

                if ($foreshadowing->requiresLegacyStatusMigration()) {
                    $timingLabel .= '；内容状态待迁移';
                }

                return [$foreshadowing->getKey() => "{$foreshadowing->title} · {$timingLabel}"];
            })
            ->all();
    }
}
