<?php

namespace App\Filament\Pages;

use App\Actions\Generation\StartMvpSoakRunAction;
use App\Actions\Generation\StartReliabilityRunAction;
use App\Actions\Generation\StartSmokeRunAction;
use App\AI\Exceptions\BudgetExceededException;
use App\Exceptions\GenerationPreflightException;
use App\Filament\Actions\EmergencyStopAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Novel;
use App\Services\DashboardOperationsOverview;
use App\Services\GenerationSettingsService;
use App\Services\MvpSoakRunService;
use App\Services\ReliabilityRunService;
use App\Services\SmokeRunService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Validation\ValidationException;

class Dashboard extends BaseDashboard
{
    protected static ?string $navigationLabel = '创作总览';

    protected static ?string $title = '创作总览';

    /** @var array<string, int|string>|null */
    private ?array $cachedStats = null;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createNovel')->label('创建小说')->icon('heroicon-o-plus')->url(NovelResource::getUrl('create')),
            Action::make('continueCurrentNovel')
                ->label('继续当前小说')
                ->icon('heroicon-o-book-open')
                ->visible(fn (): bool => $this->currentNovel() !== null)
                ->url(fn (): string => NovelResource::getUrl('view', ['record' => $this->currentNovel()])),
            Action::make('openRecoveryCenter')->label('打开恢复中心')->icon('heroicon-o-lifebuoy')->color('gray')->url(Generation::getUrl()),
            EmergencyStopAction::make(),
        ];
    }

    public function content(Schema $schema): Schema
    {
        // 代码切换可能先于运行库迁移，此时显示明确部署前置条件，不能查询不存在的新表。
        if (! \Illuminate\Support\Facades\Schema::hasTable('novel_generation_tasks')) {
            return $schema->components([Section::make('新版生成尚未部署')->description('请按运维手册核对并执行 R01–R04 数据库迁移，随后重载本项目 Worker。当前未查询或修改旧小说。')]);
        }

        // 总览围绕整书任务；高级 Run 诊断仍可从生成页进入。
        return $schema->components([View::make('filament.components.book-dashboard')]);
    }

    private function smokeRunAction(): Action
    {
        return $this->acceptanceAction('startSmokeRun', '启动 20 章长跑', 'heroicon-o-play-circle', 'primary', '启动 20 章 Smoke Run', '系统会沿用现有自动生成主链，每次只推进下一章，并在连续完成 20 个 Canonical Chapter 后自动停止。')
            ->action(function (array $data, StartSmokeRunAction $start): void {
                $this->startAcceptanceRun($data, fn (Novel $novel) => $start->handle($novel), '20 章长跑', '后续章节将在上一章正式提交后依次启动。');
            });
    }

    private function reliabilityRunAction(): Action
    {
        return $this->acceptanceAction('startReliabilityRun', '启动 50 章可靠性长跑', 'heroicon-o-shield-check', 'success', '启动 50 章 Reliability Run', '系统会串行推进 50 个正式章节，并持续汇总重试、恢复、记忆、审校、成本和上下文指标。')
            ->action(function (array $data, StartReliabilityRunAction $start): void {
                $this->startAcceptanceRun($data, fn (Novel $novel) => $start->handle($novel), '50 章可靠性长跑', '达到 50 章边界后将自动停止。');
            });
    }

    private function mvpSoakRunAction(): Action
    {
        return $this->acceptanceAction('startMvpSoakRun', '启动 100 章浸泡测试', 'heroicon-o-rocket-launch', 'warning', '启动 100 章 MVP Soak Test', '系统会沿用现有生成主链串行推进 100 个正式章节，并审计状态完整性与费用可追踪性。')
            ->action(function (array $data, StartMvpSoakRunAction $start): void {
                $this->startAcceptanceRun($data, fn (Novel $novel) => $start->handle($novel), '100 章 MVP 浸泡测试', '达到 100 章边界后将自动停止。');
            });
    }

    private function acceptanceAction(string $name, string $label, string $icon, string $color, string $heading, string $description): Action
    {
        return Action::make($name)
            ->label($label)->icon($icon)->color($color)->modalHeading($heading)->modalDescription($description)->modalSubmitActionLabel('确认启动')->requiresConfirmation()
            ->schema([
                Select::make('novel_id')->label('小说')->options(fn (): array => Novel::query()->whereIn('status', ['generating', 'completing'])->orderBy('title')->pluck('title', 'id')->all())->searchable()->required(),
            ]);
    }

    private function startAcceptanceRun(array $data, callable $start, string $label, string $successDetail): void
    {
        try {
            $chapter = $start(Novel::query()->findOrFail($data['novel_id']));
        } catch (GenerationPreflightException|BudgetExceededException|ValidationException $exception) {
            Notification::make()->title('无法启动 '.$label)->body($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title($label.'已启动')->body('已排队第 '.$chapter->sequence.' 章，'.$successDetail)->success()->send();
    }

    private function smokeRunSection(): Section
    {
        return Section::make('20 章长跑进度')->description('Long Run Progress · 连续章节、Canonical State 与实际 Provider 成本')->schema([
            RepeatableEntry::make('smoke_runs')->hiddenLabel()->state(fn (): array => $this->smokeRunProgress())->columns(['default' => 2, 'lg' => 6])->schema([
                TextEntry::make('novel')->label('小说')->weight('medium'), TextEntry::make('status')->label('状态')->badge(), TextEntry::make('progress')->label('章节进度')->badge(), TextEntry::make('cost')->label('可追踪成本')->fontFamily('mono'), TextEntry::make('sequence_health')->label('章节连续性')->badge(), TextEntry::make('state_health')->label('State 连续性')->badge(),
            ]),
            EmptyState::make('尚未启动 20 章长跑')->contained(false)->visible(fn (): bool => app(SmokeRunService::class)->allProgress()->isEmpty()),
        ]);
    }

    private function reliabilityRunSection(): Section
    {
        return Section::make('50 章可靠性汇总')->description('Reliability Summary · 生成恢复、记忆、审校、成本与上下文趋势')->schema([
            RepeatableEntry::make('reliability_runs')->hiddenLabel()->state(fn (): array => $this->reliabilityRunSummaries())->columns(['default' => 2, 'lg' => 4])->schema([
                TextEntry::make('novel')->label('小说')->weight('medium'), TextEntry::make('status')->label('状态')->badge(), TextEntry::make('progress')->label('章节进度')->badge(), TextEntry::make('continuity')->label('连续性')->badge(), TextEntry::make('recovery')->label('重试 / Worker 恢复'), TextEntry::make('memory')->label('记忆 / 向量'), TextEntry::make('story')->label('伏笔 / 审校 / 重写'), TextEntry::make('cost_context')->label('成本 / 上下文')->fontFamily('mono'),
            ]),
            EmptyState::make('尚未启动 50 章可靠性长跑')->contained(false)->visible(fn (): bool => app(ReliabilityRunService::class)->allSummaries()->isEmpty()),
        ]);
    }

    private function mvpReadinessSection(): Section
    {
        return Section::make('MVP Readiness')->description('100 章浸泡测试的 Canonical、Story State、安全门禁与费用验收')->schema([
            RepeatableEntry::make('mvp_readiness')->hiddenLabel()->state(fn (): array => $this->mvpReadiness())->columns(['default' => 2, 'lg' => 4])->schema([
                TextEntry::make('novel')->label('小说')->weight('medium'), TextEntry::make('readiness')->label('就绪状态')->badge(), TextEntry::make('progress')->label('Canonical 进度')->badge(), TextEntry::make('integrity')->label('正式数据完整性')->badge(), TextEntry::make('guards')->label('生成安全门禁'), TextEntry::make('story_guards')->label('故事规则门禁'), TextEntry::make('usage')->label('费用可追踪性')->badge(), TextEntry::make('status')->label('运行状态'),
            ]),
            EmptyState::make('尚未启动 100 章 MVP 浸泡测试')->contained(false)->visible(fn (): bool => app(MvpSoakRunService::class)->allSummaries()->isEmpty()),
        ]);
    }

    /** @return array<string, int|string> */
    private function stats(): array
    {
        return $this->cachedStats ??= app(DashboardOperationsOverview::class)->stats();
    }

    private function currentNovel(): ?Novel
    {
        return app(DashboardOperationsOverview::class)->currentNovel();
    }

    /** @return array<int, array<string, mixed>> */
    private function recentRuns(): array
    {
        return collect(app(DashboardOperationsOverview::class)->recentRuns())->map(function (array $run): array {
            $run['action_url'] = $run['chapter_id'] === null || $run['is_failed']
                ? Generation::getUrl(['tableAction' => 'inspect', 'tableActionRecord' => $run['run_id']])
                : NovelResource::getUrl('chapter', ['record' => $run['novel_id'], 'chapter' => $run['chapter_id']]);

            return $run;
        })->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function smokeRunProgress(): array
    {
        return app(SmokeRunService::class)->allProgress()->map(fn ($progress): array => [
            'novel' => $progress->novelTitle, 'status' => $progress->statusLabel(), 'progress' => "{$progress->canonicalChapters}/20 · {$progress->percent()}%", 'cost' => $this->costCurrency().' '.number_format($progress->cost, 4), 'sequence_health' => $progress->sequenceHealthy() ? '正常' : '异常', 'state_health' => $progress->stateContinuous ? '正常' : '异常',
        ])->all();
    }

    /** @return array<int, array<string, string>> */
    private function reliabilityRunSummaries(): array
    {
        return app(ReliabilityRunService::class)->allSummaries()->map(function ($summary): array {
            $drift = $summary->costDriftPercent === null ? '样本不足' : sprintf('%+.2f%%', $summary->costDriftPercent);

            return ['novel' => $summary->novelTitle, 'status' => $summary->statusLabel(), 'progress' => "{$summary->canonicalChapters}/50 · {$summary->percent()}%", 'continuity' => $summary->sequenceContinuous && $summary->stateContinuous ? '正常' : '异常', 'recovery' => "重试 {$summary->retryRuns} · Worker {$summary->workerRecoveries}/{$summary->workerCrashes}", 'memory' => "{$summary->memories} 条 · 已向量化 {$summary->embeddedMemories}", 'story' => "伏笔 {$summary->foreshadowingEvents} · 审校 {$summary->reviews} · 重写 {$summary->rewrites}", 'cost_context' => $this->costCurrency().' '.number_format($summary->cost, 4)." · 漂移 {$drift} · Context 平均 ".number_format($summary->averageContextTokens).' / 最大 '.number_format($summary->maximumContextTokens)];
        })->all();
    }

    /** @return array<int, array<string, string>> */
    private function mvpReadiness(): array
    {
        return app(MvpSoakRunService::class)->allSummaries()->map(fn ($summary): array => [
            'novel' => $summary->novelTitle, 'readiness' => $summary->isReady() ? '已就绪' : '验收中', 'progress' => "{$summary->canonicalChapters}/100 · {$summary->percent()}%", 'integrity' => $summary->duplicateCanonicalCommits === 0 && $summary->missingCanonicalChapters === 0 && $summary->stateIntegrityIssues === 0 ? '正常' : '异常', 'guards' => '暂停禁止 Commit · Resume 连续性', 'story_guards' => 'Locked Fact · Critical Foreshadowing', 'usage' => $this->costCurrency().' '.number_format($summary->trackedCost, 4)." · 异常 {$summary->untraceableUsageRecords}", 'status' => $summary->statusLabel()." · 重复 {$summary->duplicateCanonicalCommits} · 跳章 {$summary->missingCanonicalChapters} · State 异常 {$summary->stateIntegrityIssues}",
        ])->all();
    }

    private function costCurrency(): string
    {
        return app(GenerationSettingsService::class)->currency();
    }
}
