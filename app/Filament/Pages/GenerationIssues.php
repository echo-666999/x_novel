<?php

namespace App\Filament\Pages;

use App\Enums\ArtifactType;
use App\Filament\Resources\AIModelPrices\AIModelPriceResource;
use App\Filament\Resources\AIProviderConnections\AIProviderConnectionResource;
use App\Models\GenerationArtifact;
use App\Models\GenerationIssue;
use App\Models\GenerationRun;
use App\Models\NovelGenerationTask;
use App\Models\UsageRecord;
use App\Services\GenerationIssueActions;
use App\Services\GenerationIssueService;
use App\Support\GenerationDiagnosticRedactor;
use BackedEnum;
use Brick\Math\BigDecimal;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** 所有入口引用同一 Issue；页面只读事实并调用受控应用动作。 */
class GenerationIssues extends Page
{
    use WithPagination;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationLabel = '异常与审校';

    protected static ?string $title = '异常中心';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.pages.generation-issues';

    #[Url]
    public string $filter = 'awaiting_user';

    #[Url]
    public ?int $novel = null;

    #[Url]
    public ?int $chapter = null;

    #[Url]
    public ?int $issue = null;

    #[Locked]
    public ?int $selectedIssueId = null;

    #[Locked]
    public array $actionPreview = [];

    #[Locked]
    public string $operationKey = '';

    public string $instructions = '';

    public ?string $feedback = null;

    public function mount(): void
    {
        $this->authorizeOperator();
        if ($this->issue && $this->isReady()) {
            $this->selectIssue($this->issue);
        }
    }

    public function isReady(): bool
    {
        return Schema::hasTable('generation_issues');
    }

    /** 单用户后台仍在每次公开操作检查登录状态，不能仅隐藏按钮。 */
    private function authorizeOperator(): void
    {
        abort_unless(auth()->user()?->canAccessPanel(Filament::getCurrentPanel() ?? Filament::getPanel('x')), 403);
    }

    public function issues(): ?LengthAwarePaginator
    {
        $this->authorizeOperator();
        if (! $this->isReady()) {
            return null;
        }

        return GenerationIssue::with('novel')->when($this->novel, fn ($q) => $q->where('novel_id', $this->novel))
            ->when($this->chapter, fn ($q) => $q->where('chapter_id', $this->chapter))
            ->when($this->filter === 'awaiting_user', fn ($q) => $q->where('status', 'awaiting_user'))
            ->when($this->filter === 'automatic', fn ($q) => $q->whereIn('status', ['diagnosing', 'repairing', 'open']))
            ->when($this->filter === 'unresolved', fn ($q) => $q->where('status', '!=', 'resolved'))
            ->when($this->filter === 'resolved', fn ($q) => $q->where('status', 'resolved'))
            ->latest('last_seen_at')->paginate(15);
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function selectIssue(int $id): void
    {
        $this->authorizeOperator();
        GenerationIssue::findOrFail($id);
        $this->selectedIssueId = $id;
        $this->issue = $id;
        $this->actionPreview = [];
        $this->operationKey = (string) Str::uuid();
        $this->feedback = null;
        // 不清空人工输入；切换问题也可复制尚未保存的说明。
    }

    public function selected(): ?GenerationIssue
    {
        return $this->selectedIssueId ? GenerationIssue::with('novel')->find($this->selectedIssueId) : null;
    }

    public function options(GenerationIssue $issue): array
    {
        $options = [];
        foreach (['retry_export' => '仅重新导出正式正文', 'retry_transient' => '从原断点重试', 'repair_foundation' => '请 AI 修复设定字段', 'repair_book' => '请 AI 修复失败产物或局部正文', 'rebuild_book_context' => '保留正文并重建容量准入', 'diagnose' => '请 AI 分析原因', 'save_note' => '保存人工处理说明', 'pause' => '暂停整书任务'] as $action => $label) {
            if ($action === 'rebuild_book_context' && ! in_array($issue->category, ['capacity', 'configuration', 'summary'], true)) {
                continue;
            }
            if ($action === 'retry_export' && $issue->category !== 'export') {
                continue;
            }
            if ($action === 'repair_book' && ! str_starts_with($issue->error_code, 'book_')) {
                continue;
            }
            // 设定修复只出现在已保存的新版设定错误上，其他领域维持原动作集合。
            if ($action === 'repair_foundation' && ! preg_match('/^(foundation_(schema|evidence|output)|intent_hard_constraint)/', $issue->error_code)) {
                continue;
            }
            if ($action === 'retry_transient' && ! data_get($issue->evidence, 'policy.retryable')) {
                continue;
            }
            try {
                $preview = app(GenerationIssueActions::class)->preview($issue, $action, $this->instructions);
            } catch (\Throwable $exception) {
                $preview = ['available' => false, 'reason' => app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage())];
            }
            $options[] = ['action' => $action, 'label' => $label, ...$preview];
        }

        return $options;
    }

    public function prepare(string $action): void
    {
        $this->authorizeOperator();
        try {
            $preview = app(GenerationIssueActions::class)->preview(GenerationIssue::findOrFail($this->selectedIssueId), $action, $this->instructions);
            if (! $preview['available']) {
                throw new \LogicException($preview['reason']);
            }
            // 完整 Prompt 合同留在服务端；浏览器只接收影响预览和 CAS 身份。
            unset($preview['request_contract']);
            $this->actionPreview = $preview;
            $this->operationKey = (string) Str::uuid();
            $this->feedback = null;
        } catch (\Throwable $exception) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
        }
    }

    public function submit(): void
    {
        $this->authorizeOperator();
        try {
            if ($this->actionPreview === []) {
                throw new \LogicException('请先选择方案并查看影响范围。');
            }
            $this->validate(['instructions' => 'string|max:6000']);
            $preview = $this->actionPreview;
            $issue = GenerationIssue::findOrFail($preview['issue_id']);
            app(GenerationIssueActions::class)->execute($issue->id, $preview['action'],
                $preview['fingerprint'],
                $preview['control_version'], $this->operationKey, $this->instructions, auth()->id());
            $this->feedback = '操作已记录。只有同来源复验通过才会关闭问题；可刷新查看处理结果。';
            $this->actionPreview = [];
        } catch (\Throwable $exception) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
            // 请求失败保留 instructions 和原预览，明确版本过期后重新查看差异。
        }
    }

    /** 每种币种分别统计，未知项和占用独立展示，避免未知记零或隐式换汇。 */
    public function costs(GenerationIssue $issue): array
    {
        return UsageRecord::where('issue_id', $issue->id)->get()->groupBy('currency')->map(function ($rows, $currency): array {
            $known = BigDecimal::zero();
            $reserved = BigDecimal::zero();
            $unknown = 0;
            $knownCount = 0;
            foreach ($rows as $row) {
                $amount = $row->actual_cost ?? $row->estimated_cost;
                if ($amount === null && $row->status !== 'not_sent') {
                    $unknown++;
                } elseif ($amount !== null) {
                    $known = $known->plus($amount);
                    $knownCount++;
                }
                $reserved = $reserved->plus($row->reserved_amount ?? '0');
            }

            return ['currency' => $currency, 'known' => $knownCount > 0 ? (string) $known : null, 'reserved' => (string) $reserved, 'unknown' => $unknown];
        })->values()->all();
    }

    public function details(GenerationIssue $issue): array
    {
        $run = GenerationRun::find($issue->latest_run_id);
        $task = NovelGenerationTask::findOrFail($issue->generation_task_id);
        $proposal = $run?->result_artifact_id ? GenerationArtifact::find($run->result_artifact_id) : null;
        $currentError = null;
        try {
            if ($issue->status !== 'resolved') {
                app(GenerationIssueService::class)->assertCurrent($issue);
            }
        } catch (\Throwable $exception) {
            $currentError = $exception->getMessage();
        }

        return app(GenerationDiagnosticRedactor::class)->clean(['run' => $run?->only(['id', 'stage', 'status', 'provider', 'model', 'error_code', 'error_message', 'next_attempt_at']),
            'task_status' => $task->status, 'source_error' => $currentError,
            'proposal' => $proposal?->type === ArtifactType::RepairProposal ? $proposal->data : null,
            'operations' => GenerationArtifact::whereIn('id', array_column($issue->evidence['operations'] ?? [], 'artifact_id'))->get(['id', 'data'])->toArray(),
            'evidence' => $issue->evidence, 'source' => $issue->source_identity, 'verification' => $issue->verification]);
    }

    /** 下载内容复用页面脱敏结果，不包含凭据、完整 Prompt 或未筛选的日志。 */
    public function downloadDiagnostic(): StreamedResponse
    {
        $this->authorizeOperator();
        $issue = GenerationIssue::findOrFail($this->selectedIssueId);
        $data = ['issue_id' => $issue->id, 'error_code' => $issue->error_code, 'status' => $issue->status,
            'observed_at' => now()->toISOString(), 'details' => $this->details($issue)];

        return response()->streamDownload(fn () => print (json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            'issue-'.$issue->id.'-diagnostic.json', ['Content-Type' => 'application/json']);
    }

    public function settingUrl(GenerationIssue $issue): string
    {
        return match (data_get($issue->evidence, 'policy.setting')) {
            'connections' => AIProviderConnectionResource::getUrl('index'),
            'prices' => AIModelPriceResource::getUrl('index'),
            default => Settings::getUrl(),
        };
    }
}
