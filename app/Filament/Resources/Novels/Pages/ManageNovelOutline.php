<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\RestartChapterFromOutlineAction;
use App\Actions\Novels\ApplyNovelBlueprintAction;
use App\Actions\Novels\ApplyNovelOutlineRevisionAction;
use App\Actions\Novels\CreateNovelOutlineVersionAction;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcType;
use App\Enums\WorldEntityType;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\GenerationArtifact;
use App\Models\NovelOutline;
use App\Services\NovelOutlineValidator;
use App\Services\NovelPlanner;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class ManageNovelOutline extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationLabel = '全书大纲';

    public function getTitle(): string
    {
        return '全书大纲';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.resources.novels.pages.outline-builder')
                ->viewData(fn (): array => [
                    'outline' => $this->displayedOutline(),
                    'versions' => $this->getRecord()->outlines()->get(),
                    'validation' => ($outline = $this->displayedOutline()) === null
                        ? null
                        : app(NovelOutlineValidator::class)->validate($outline->content),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generateOutlineCandidate')
                ->label('AI 生成候选')
                ->icon('heroicon-o-sparkles')
                ->visible(fn (): bool => $this->getRecord()->current_outline_id === null && ! $this->hasFormalStructure())
                ->schema([
                    TextInput::make('volume_count')->label('预计分卷数')->integer()->minValue(1)->maxValue(12)->default(5)->required(),
                ])
                ->action(function (array $data, NovelPlanner $planner): void {
                    try {
                        $planner->generate($this->getRecord(), (int) $data['volume_count']);
                    } catch (\Throwable $exception) {
                        Notification::make()->title('大纲候选生成失败')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('AI 大纲候选已保存为 Draft Version')->success()->send();
                }),
            Action::make('saveManualOutline')
                ->label(fn (): string => $this->latestDraft() === null ? '手工创建' : '编辑为新版本')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $this->getRecord()->current_outline_id === null && ! $this->hasFormalStructure())
                ->modalHeading(fn (): string => $this->latestDraft() === null ? '手工创建全书大纲' : '创建大纲修订版本')
                ->modalDescription('保存会创建新的不可变 Draft Version，不覆盖旧版本。')
                ->modalWidth('7xl')
                ->fillForm(fn (): array => $this->latestDraft()?->content ?? $this->emptyOutline())
                ->schema(self::outlineForm())
                ->action(function (array $data, CreateNovelOutlineVersionAction $create): void {
                    $base = $this->latestDraft();
                    $data = $this->synchronizeSequences($data);
                    $create->handle(
                        novel: $this->getRecord(),
                        content: $data,
                        source: $base === null ? NovelOutlineSource::Manual : NovelOutlineSource::Revision,
                        basedOn: $base,
                        creator: auth()->user(),
                    );
                    $this->getRecord()->refresh();
                    Notification::make()->title('大纲 Draft Version 已创建')->success()->send();
                }),
            Action::make('regenerateOutlineNode')
                ->label('局部重新生成')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $this->latestDraft() !== null && $this->latestBlueprintArtifact() !== null && ! $this->hasFormalStructure())
                ->schema([
                    Select::make('node_key')
                        ->label('目标节点')
                        ->options(fn (NovelPlanner $planner): array => collect($planner->nodeKeys($this->latestDraft()?->content ?? []))->mapWithKeys(fn (string $key): array => [$key => $key])->all())
                        ->searchable()
                        ->required(),
                    Textarea::make('instruction')->label('修改要求')->rows(5)->required()->maxLength(2000),
                ])
                ->action(function (array $data, NovelPlanner $planner): void {
                    try {
                        $planner->regenerateNode(
                            $this->getRecord(),
                            $this->latestDraft(),
                            $this->latestBlueprintArtifact(),
                            $data['node_key'],
                            $data['instruction'],
                        );
                    } catch (\Throwable $exception) {
                        Notification::make()->title('局部重新生成失败')->body($exception->getMessage())->danger()->send();

                        return;
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('局部修订已保存为新 Draft Version')->success()->send();
                }),
            Action::make('applyOutline')
                ->label('确认采用')
                ->icon('heroicon-o-check-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->latestDraft() !== null && ! $this->hasFormalStructure())
                ->requiresConfirmation()
                ->modalHeading('采用当前 Draft Outline')
                ->modalDescription('采用将在一个事务内创建正式分卷、故事线和初始状态。AI 候选还会创建其冻结 Artifact 中的 Bible、初始人物、世界资料和伏笔。')
                ->action(function (ApplyNovelBlueprintAction $apply): void {
                    $outline = $this->latestDraft();
                    try {
                        $apply->handle(
                            $this->getRecord(),
                            $outline,
                            $this->aiRoot($outline) ? $this->latestBlueprintArtifact() : null,
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法采用大纲')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }
                    $this->getRecord()->refresh();
                    Notification::make()->title('Current Novel Outline 已采用')->success()->send();
                }),
            Action::make('applyOutlineRevision')
                ->label('从下一章生效')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord()->current_outline_id !== null)
                ->modalHeading('修订未来大纲并从下一章生效')
                ->modalDescription('保存会创建并采用新的不可变 Outline Version。当前非正式章继续使用其已冻结的旧 Chapter Plan；正式内容和正在使用的节点不会被改写。')
                ->modalWidth('7xl')
                ->fillForm(function (): array {
                    $current = $this->getRecord()->currentOutline()->firstOrFail();

                    return [
                        ...$current->content,
                        'expected_current_outline_id' => $current->getKey(),
                        'expected_current_outline_checksum' => $current->checksum,
                    ];
                })
                ->schema([
                    Hidden::make('expected_current_outline_id')->required(),
                    Hidden::make('expected_current_outline_checksum')->required(),
                    ...self::outlineForm(),
                ])
                ->action(function (array $data, ApplyNovelOutlineRevisionAction $apply): void {
                    $expectedId = (int) $data['expected_current_outline_id'];
                    $expectedChecksum = (string) $data['expected_current_outline_checksum'];
                    unset($data['expected_current_outline_id'], $data['expected_current_outline_checksum']);

                    try {
                        $apply->handle(
                            novel: $this->getRecord(),
                            content: $this->synchronizeSequences($data),
                            expectedCurrentOutlineId: $expectedId,
                            expectedCurrentChecksum: $expectedChecksum,
                            creator: auth()->user(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法修订大纲')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    Notification::make()->title('新 Outline Version 已从下一章生效')->success()->send();
                }),
            Action::make('restartChapterFromOutline')
                ->label('重建当前非正式章')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (): bool => $this->currentNonCanonicalChapterNeedsRestart())
                ->requiresConfirmation()
                ->modalHeading('按 Current Outline 重建当前非正式章')
                ->modalDescription('旧 Chapter Plan、Generation Run、Artifact、Review 和 Usage 会完整保留。系统只替代当前执行指针，并从 Chapter Planning 创建新的来源链。')
                ->fillForm(function (): array {
                    $current = $this->getRecord()->currentOutline()->firstOrFail();

                    return [
                        'expected_outline_id' => $current->getKey(),
                        'expected_outline_checksum' => $current->checksum,
                    ];
                })
                ->schema([
                    Hidden::make('expected_outline_id')->required(),
                    Hidden::make('expected_outline_checksum')->required(),
                ])
                ->action(function (array $data, RestartChapterFromOutlineAction $restart): void {
                    try {
                        $result = $restart->handle(
                            novel: $this->getRecord(),
                            expectedOutlineId: (int) $data['expected_outline_id'],
                            expectedOutlineChecksum: (string) $data['expected_outline_checksum'],
                            actorId: auth()->id(),
                        );
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法重建当前章')->body(collect($exception->errors())->flatten()->first())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    Notification::make()
                        ->title($result['dispatched'] ? '当前章已进入重新规划' : '当前章已重置，规划任务已存在')
                        ->success()
                        ->send();
                }),
        ];
    }

    /** @return array<int, mixed> */
    private static function outlineForm(): array
    {
        return [
            Section::make('全书约束')->columns(2)->schema([
                TextInput::make('title')->label('大纲标题')->required()->maxLength(255),
                Textarea::make('summary')->label('全书摘要')->required()->rows(3)->columnSpanFull(),
                TagsInput::make('must_include')->label('必须包含')->default([]),
                TagsInput::make('must_not_include')->label('禁止包含')->default([]),
                Hidden::make('baseline_completions')->default([]),
            ]),
            Repeater::make('volumes')
                ->label('Volumes')
                ->addActionLabel('添加 Volume')
                ->reorderable()
                ->collapsible()
                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                ->schema([
                    TextInput::make('key')->label('稳定 Key')->required(),
                    TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                    TextInput::make('title')->label('标题')->required(),
                    TextInput::make('target_words')->label('目标字数')->integer()->minValue(1)->required(),
                    Textarea::make('goal')->label('目标')->required()->rows(2),
                    Textarea::make('climax')->label('高潮')->required()->rows(2),
                    Repeater::make('arcs')->label('Story Arcs')->addActionLabel('添加 Arc')->reorderable()->collapsible()
                        ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                        ->schema([
                            TextInput::make('key')->label('稳定 Key')->required(),
                            TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                            Select::make('type')->label('类型')->options(StoryArcType::class)->required(),
                            TextInput::make('title')->label('标题')->required(),
                            Textarea::make('goal')->label('目标')->required()->rows(2),
                            Textarea::make('stakes')->label('风险 / 代价')->required()->rows(2),
                            TagsInput::make('completion_conditions')->label('完成条件')->required(),
                            Repeater::make('beats')->label('Outline Beats')->addActionLabel('添加 Beat')->reorderable()->collapsible()
                                ->itemLabel(fn (array $state): ?string => $state['title'] ?? null)
                                ->schema([
                                    TextInput::make('key')->label('稳定 Key')->required(),
                                    TextInput::make('sequence')->label('顺序')->integer()->minValue(1)->required(),
                                    TextInput::make('title')->label('标题')->required(),
                                    Textarea::make('summary')->label('节点摘要')->required()->rows(2),
                                    TextInput::make('chapter_budget.min')->label('最少章节')->integer()->minValue(1)->required(),
                                    TextInput::make('chapter_budget.max')->label('最多章节')->integer()->minValue(1)->nullable(),
                                    TagsInput::make('acceptance_criteria')->label('验收条件')->required(),
                                    TagsInput::make('must_include')->label('必须包含')->default([]),
                                    TagsInput::make('must_not_include')->label('禁止包含')->default([]),
                                    Repeater::make('character_candidates')->label('未来人物 Candidate')->default([])->collapsible()->schema([
                                        TextInput::make('candidate_key')->label('Candidate Key')->required(),
                                        TextInput::make('name')->label('名称')->required(),
                                        TextInput::make('role')->label('角色定位')->required(),
                                        TextInput::make('motivation')->label('动机')->required(),
                                        KeyValue::make('profile')->label('档案')->default([]),
                                        KeyValue::make('personality')->label('性格')->default([]),
                                        KeyValue::make('abilities')->label('能力')->default([]),
                                        KeyValue::make('knowledge')->label('知识')->default([]),
                                        Textarea::make('deduplication_basis')->label('去重依据')->required(),
                                        Hidden::make('possible_duplicate_character_ids')->default([]),
                                        Textarea::make('introduction_reason')->label('引入理由')->required(),
                                        TextInput::make('target_scene_sequence')->label('目标 Scene')->integer()->minValue(1)->required(),
                                    ]),
                                    Repeater::make('world_entity_candidates')->label('未来世界实体 Candidate')->default([])->collapsible()->schema([
                                        TextInput::make('candidate_key')->label('Candidate Key')->required(),
                                        Select::make('type')->label('类型')->options(WorldEntityType::class)->required(),
                                        TextInput::make('name')->label('名称')->required(),
                                        Textarea::make('description')->label('描述')->required(),
                                        Textarea::make('deduplication_basis')->label('去重依据')->required(),
                                        Hidden::make('possible_duplicate_entity_ids')->default([]),
                                        Textarea::make('introduction_reason')->label('引入理由')->required(),
                                        TextInput::make('target_scene_sequence')->label('目标 Scene')->integer()->minValue(1)->required(),
                                    ]),
                                ])->columns(2),
                        ])->columns(2),
                ])->columns(2),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyOutline(): array
    {
        return [
            'title' => $this->getRecord()->title.'全书大纲',
            'summary' => (string) $this->getRecord()->premise,
            'must_include' => [],
            'must_not_include' => [],
            'baseline_completions' => [],
            'volumes' => [],
        ];
    }

    private function displayedOutline(): ?NovelOutline
    {
        return $this->getRecord()->currentOutline ?? $this->latestDraft() ?? $this->getRecord()->outlines()->first();
    }

    private function latestDraft(): ?NovelOutline
    {
        return $this->getRecord()->outlines()->where('status', NovelOutlineStatus::Draft->value)->latest('version')->first();
    }

    private function hasFormalStructure(): bool
    {
        return $this->getRecord()->current_outline_id !== null
            || $this->getRecord()->volumes()->exists()
            || $this->getRecord()->storyArcs()->exists();
    }

    private function currentNonCanonicalChapterNeedsRestart(): bool
    {
        $novel = $this->getRecord();
        if ($novel->current_outline_id === null) {
            return false;
        }

        $chapter = $novel->chapters()
            ->where('sequence', ((int) $novel->current_chapter_sequence) + 1)
            ->where('status', '!=', ChapterStatus::Canonical->value)
            ->with('latestPlan')
            ->first();

        return $chapter?->latestPlan !== null
            && $chapter->latestPlan->novel_outline_id !== $novel->current_outline_id;
    }

    private function aiRoot(?NovelOutline $outline): bool
    {
        while ($outline?->based_on_outline_id !== null) {
            $outline = $outline->basedOn;
        }

        return $outline?->source === NovelOutlineSource::Ai;
    }

    private function latestBlueprintArtifact(): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::Context)
            ->whereHas('generationRun', fn ($query) => $query
                ->where('novel_id', $this->getRecord()->getKey())
                ->whereNull('chapter_id')
                ->where('scope_type', 'novel')
                ->where('stage', GenerationStage::ChapterPlanning)
                ->where('status', RunStatus::Succeeded))
            ->latest('id')
            ->first();
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    private function synchronizeSequences(array $content): array
    {
        $volumes = array_values($content['volumes'] ?? []);
        foreach ($volumes as $volumeIndex => &$volume) {
            $volume['sequence'] = $volumeIndex + 1;
            $arcs = array_values($volume['arcs'] ?? []);
            foreach ($arcs as $arcIndex => &$arc) {
                $arc['sequence'] = $arcIndex + 1;
                if (($arc['type'] ?? null) instanceof \BackedEnum) {
                    $arc['type'] = $arc['type']->value;
                }
                $beats = array_values($arc['beats'] ?? []);
                foreach ($beats as $beatIndex => &$beat) {
                    $beat['sequence'] = $beatIndex + 1;
                    foreach ($beat['world_entity_candidates'] ?? [] as &$candidate) {
                        if (($candidate['type'] ?? null) instanceof \BackedEnum) {
                            $candidate['type'] = $candidate['type']->value;
                        }
                    }
                    unset($candidate);
                }
                unset($beat);
                $arc['beats'] = $beats;
            }
            unset($arc);
            $volume['arcs'] = $arcs;
        }
        unset($volume);
        $content['volumes'] = $volumes;

        return $content;
    }
}
