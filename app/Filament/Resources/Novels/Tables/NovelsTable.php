<?php

namespace App\Filament\Resources\Novels\Tables;

use App\Actions\Novels\DeleteNovelAction;
use App\Enums\NovelStatus;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NovelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('标题')
                    ->searchable()
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('genre')
                    ->label('题材')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状态')
                    ->badge()
                    ->sortable(),
                TextColumn::make('target_words')
                    ->label('目标字数')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('current_chapter_sequence')
                    ->label('当前章节')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : "第 {$state} 章")
                    ->alignEnd(),
                TextColumn::make('updated_at')
                    ->label('更新时间')
                    ->dateTime('Y-m-d H:i')
                    ->sinceTooltip()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状态')
                    ->options(NovelStatus::class),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('暂无小说')
            ->emptyStateDescription('创建第一部小说，开始建立长篇故事工作台。')
            ->emptyStateIcon('heroicon-o-book-open')
            ->recordUrl(fn (Novel $record): string => NovelResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->label('进入工作台'),
                EditAction::make()->label('编辑'),
                Action::make('deleteNovel')
                    ->label('删除小说')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->disabled(fn (Novel $record): bool => $record->status !== NovelStatus::Paused)
                    ->tooltip(fn (Novel $record): ?string => $record->status !== NovelStatus::Paused
                        ? '必须先暂停小说，并确认没有排队中或运行中的 Generation Run。'
                        : null)
                    ->requiresConfirmation()
                    ->modalHeading(fn (Novel $record): string => "永久删除《{$record->title}》？")
                    ->modalDescription('将物理删除整部小说及全部正文、小说大纲、正式故事状态、记忆和生成追踪数据。成功后只能从数据库备份恢复。')
                    ->modalSubmitActionLabel('确认永久删除整部小说')
                    ->modalWidth('4xl')
                    ->fillForm(fn (Novel $record, DeleteNovelAction $delete): array => [
                        'impact' => $delete->impact($record),
                        'expected_title' => null,
                        'reason' => null,
                    ])
                    ->schema([
                        Section::make('删除影响预览')
                            ->description('以下记录将从数据库物理删除，不保留应用内恢复入口。')
                            ->columns(4)
                            ->schema([
                                TextEntry::make('impact.outlines')->label('小说大纲'),
                                TextEntry::make('impact.outline_volumes')->label('大纲分卷'),
                                TextEntry::make('impact.outline_arcs')->label('大纲故事线'),
                                TextEntry::make('impact.outline_beats')->label('大纲节点'),
                                TextEntry::make('impact.outline_milestones')->label('里程碑'),
                                TextEntry::make('impact.volumes')->label('分卷'),
                                TextEntry::make('impact.story_arcs')->label('故事线'),
                                TextEntry::make('impact.chapters')->label('章节'),
                                TextEntry::make('impact.plans')->label('章节计划'),
                                TextEntry::make('impact.scenes')->label('场景'),
                                TextEntry::make('impact.state_versions')->label('故事状态版本'),
                                TextEntry::make('impact.events')->label('故事事件'),
                                TextEntry::make('impact.facts')->label('事实'),
                                TextEntry::make('impact.memories')->label('记忆'),
                                TextEntry::make('impact.characters')->label('人物'),
                                TextEntry::make('impact.world_entities')->label('世界实体'),
                                TextEntry::make('impact.foreshadowings')->label('伏笔'),
                                TextEntry::make('impact.runs')->label('生成记录'),
                                TextEntry::make('impact.artifacts')->label('生成产物'),
                                TextEntry::make('impact.reviews')->label('审校记录'),
                                TextEntry::make('impact.usage')->label('AI 用量'),
                                TextEntry::make('impact.bibles')->label('小说圣经'),
                            ]),
                        TextInput::make('expected_title')
                            ->label('输入完整小说标题确认')
                            ->helperText(fn (Novel $record): string => "必须完整输入：{$record->title}")
                            ->required()
                            ->maxLength(255),
                        Textarea::make('reason')
                            ->label('删除原因')
                            ->helperText('日志只记录小说 ID、标题、执行人、原因和数量，不记录正文或 Prompt。')
                            ->required()
                            ->maxLength(2000)
                            ->rows(3),
                    ])
                    ->action(function (Novel $record, array $data, DeleteNovelAction $delete): void {
                        $result = $delete->execute(
                            $record,
                            (string) $data['expected_title'],
                            (string) $data['reason'],
                            auth()->id(),
                        );

                        Notification::make()
                            ->title($result['status'] === 'already_deleted' ? '小说已经删除' : '小说及全部关联数据已删除')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
