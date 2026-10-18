<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Novels\CompleteNovelAction;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Filament\Pages\GenerationIssues;
use App\Filament\Resources\Novels\NovelResource;
use App\Jobs\EndingAuditJob;
use App\Models\GenerationArtifact;
use App\Models\NovelGenerationTask;
use App\Services\BookEnding;
use App\Support\GenerationDiagnosticRedactor;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ViewNovelEndingAudit extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = '结局审计';

    public function getTitle(): string
    {
        return '结局审计';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->title;
    }

    protected function getHeaderActions(): array
    {
        if ($this->getRecord()->current_generation_task_id) {
            $task = NovelGenerationTask::findOrFail($this->getRecord()->current_generation_task_id);

            return [Action::make('workbench')->label('返回工作台')->url(NovelResource::getUrl('view', ['record' => $this->getRecord()])),
                Action::make('extendClosing')->label('安排有限补章')->visible($task->stop_reason === 'book_closure_debt')
                    ->schema([Select::make('chapters')->label('额外补写上限')->options([1 => '1 章', 2 => '2 章', 3 => '3 章'])->default(1)->required()->native(), Textarea::make('reason')->label('要解决的承诺与收束方向')->required()->maxLength(2000)])
                    ->modalDescription('只安排未来正文；不会删除旧债务、跳过审校或修改费用上限。新审计通过前任务不会完成。')
                    ->action(function (array $data) use ($task): void {
                        try {
                            app(BookEnding::class)->extend($task->id, $task->control_version, (int) $data['chapters'], $data['reason']);
                            Notification::make()->title('有限补章已登记，债务等待复验')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('安排未生效')->body(app(GenerationDiagnosticRedactor::class)->clean($e->getMessage()))->danger()->send();
                        }
                    }),
                Action::make('issues')->label('处理未闭合项')->url(GenerationIssues::getUrl(['novel' => $this->getRecord()->id]))];
        }

        return [
            Action::make('runAudit')
                ->label($this->latestAudit() ? '重新审计' : '运行审计')
                ->icon('heroicon-o-clipboard-document-check')
                ->color('warning')
                ->visible(fn (): bool => $this->getRecord()->status === NovelStatus::Completing)
                ->action(function (): void {
                    EndingAuditJob::dispatchSync($this->getRecord()->getKey());
                    Notification::make()->title('结局审计已完成')->success()->send();
                }),
            Action::make('completeNovel')
                ->label('完成小说')
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === NovelStatus::Completing)
                ->disabled(fn (): bool => data_get($this->latestAudit()?->data, 'decision') !== 'PASS')
                ->tooltip(fn (): ?string => data_get($this->latestAudit()?->data, 'decision') === 'PASS' ? null : '结局审计 PASS 后才能完成小说。')
                ->requiresConfirmation()
                ->modalHeading('完成小说')
                ->modalDescription('小说将进入已完结状态，并停止自动生成。结局审计记录仍可查看。')
                ->action(function (CompleteNovelAction $completeNovel): void {
                    try {
                        $completeNovel->handle($this->getRecord());
                    } catch (ValidationException $exception) {
                        Notification::make()->title('无法完成小说')->body($exception->getMessage())->danger()->send();

                        return;
                    }

                    $this->getRecord()->refresh();
                    Notification::make()->title('小说已完结')->success()->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        if ($this->getRecord()->current_generation_task_id) {
            return $schema->components([View::make('filament.components.book-ending')->viewData(['novel' => $this->getRecord(), 'audit' => $this->latestAudit()])]);
        }

        return $schema->components([
            Section::make('尚未运行结局审计')
                ->description('进入收束期后运行审计，系统将检查结局契约、收束债务、伏笔、故事线、人物弧和故事状态。')
                ->icon('heroicon-o-clipboard-document-check')
                ->visible(fn (): bool => $this->latestAudit() === null),
            Section::make('审计结论')
                ->columns(['default' => 1, 'md' => 4])
                ->visible(fn (): bool => $this->latestAudit() !== null)
                ->schema([
                    TextEntry::make('novel_status')->label('小说状态')->state(fn (): NovelStatus => $this->getRecord()->status)->badge()->color(fn (NovelStatus $state): string => $state->getColor())->formatStateUsing(fn (NovelStatus $state): string => $state->getLabel()),
                    TextEntry::make('audit_decision')->label('审计结论')->state(fn (): ?string => data_get($this->latestAudit()?->data, 'decision'))->badge()->color(fn (string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                    TextEntry::make('audit_state_version')->label('故事状态版本')->state(fn (): mixed => data_get($this->latestAudit()?->data, 'state_version'))->placeholder('—'),
                    TextEntry::make('audit_bible_version')->label('小说设定版本')->state(fn (): mixed => data_get($this->latestAudit()?->data, 'bible_version'))->placeholder('—'),
                ]),
            Section::make('逐项证据')
                ->description('BLOCK 项必须处理后重新审计。')
                ->visible(fn (): bool => $this->latestAudit() !== null)
                ->schema([
                    RepeatableEntry::make('audit_checks')->hiddenLabel()->state(fn (): array => data_get($this->latestAudit()?->data, 'checks', []))->columns(['default' => 1, 'md' => 2])->schema([
                        TextEntry::make('label')->label('检查项')->weight('medium'),
                        TextEntry::make('status')->label('结果')->badge()->color(fn (string $state): string => $state === 'PASS' ? 'success' : 'danger'),
                        TextEntry::make('evidence')->label('证据')->bulleted()->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    private function latestAudit(): ?GenerationArtifact
    {
        return GenerationArtifact::query()
            ->where('type', ArtifactType::EndingAudit)
            ->whereHas('generationRun', fn ($query) => $query->where('novel_id', $this->getRecord()->getKey())->where('stage', GenerationStage::EndingAudit))
            ->latest('id')
            ->first();
    }
}
