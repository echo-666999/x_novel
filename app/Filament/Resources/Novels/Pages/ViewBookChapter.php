<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\RepairBookReviewEvidenceAction;
use App\Actions\Chapters\ReviseBookChapterDraftAction;
use App\Actions\Chapters\ReviseBookChapterEventsAction;
use App\Actions\Chapters\ReviseBookSummaryEvidenceAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\NovelGenerationTask;
use App\Models\Review;
use App\Support\GenerationDiagnosticRedactor;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/** 阅读与人工修复共用当前稿身份；服务器重新鉴权和 CAS，失败不清空编辑器。 */
class ViewBookChapter extends ViewRecord
{
    protected static string $resource = NovelResource::class;

    #[Locked]
    public int $chapterId;

    #[Locked]
    public ?int $draftId = null;

    #[Locked]
    public string $draftHash = '';

    #[Locked]
    public int $controlVersion = 0;

    #[Locked]
    public string $operationKey = '';

    public string $body = '';

    public ?string $feedback = null;

    public string $version = 'canonical';

    public int $directoryPage = 1;

    public string $directorySearch = '';

    #[Locked]
    public ?int $eventReviewId = null;

    #[Locked]
    public ?int $eventCandidateId = null;

    #[Locked]
    public string $eventCandidateHash = '';

    #[Locked]
    public int $eventControlVersion = 0;

    #[Locked]
    public string $eventOperationKey = '';

    public array $eventRows = [];

    public string $eventAdditions = '[]';

    public ?string $eventFeedback = null;

    #[Locked]
    public array $evidenceRepair = [];

    public ?string $evidenceFeedback = null;

    public function repairEvidence(): void
    {
        $chapter = $this->authorizeEdit();
        try {
            $p = $this->evidenceRepair;
            if ($p === []) {
                throw new \LogicException('当前没有可修正的保存响应。');
            }
            $artifact = app(RepairBookReviewEvidenceAction::class)->handle($chapter->id, $p['source'], $p['candidate'], $p['checksum'], $p['version'], $p['key']);
            $this->evidenceFeedback = isset($artifact->data['decision'])
                ? '定位修正已保存并通过完整校验，审校结论：'.$artifact->data['decision'].'。原响应、判断、费用和 AI 次数保留。'
                : '事件定位修正已保存并通过完整校验，仍需独立审校。原响应、事件内容、费用和 AI 次数保留。';
        } catch (\Throwable $exception) {
            $this->evidenceFeedback = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
        }
    }

    private function loadEvidenceRepair(): void
    {
        try {
            $action = app(RepairBookReviewEvidenceAction::class);
            $source = $action->source($this->authorizeEdit());
            $candidate = $action->candidate($source);
            $fixed = $action->corrected($source, $candidate);
            $this->evidenceRepair = ['source' => $source->id, 'candidate' => $candidate->id, 'checksum' => $candidate->checksum,
                'version' => NovelGenerationTask::findOrFail($source->generation_task_id)->control_version, 'key' => (string) Str::uuid(), 'changes' => $fixed['changes']];
        } catch (\Throwable) {
            $this->evidenceRepair = [];
        }
    }

    #[Locked]
    public array $summarySource = [];

    public array $summaryRows = [];

    public ?string $summaryFeedback = null;

    private function loadSummaryEvidence(): void
    {
        try {
            $action = app(ReviseBookSummaryEvidenceAction::class);
            $source = $action->source($this->authorizeEdit());
            $candidate = $action->candidate($source);
            $this->summarySource = ['source' => $source->id, 'candidate' => $candidate->id, 'checksum' => $candidate->checksum,
                'version' => NovelGenerationTask::findOrFail($source->generation_task_id)->control_version, 'key' => (string) Str::uuid(), 'points' => $candidate->data['points']];
            $this->summaryRows = $action->rows($candidate);
        } catch (\Throwable) {
            $this->summarySource = [];
        }
    }

    public function saveSummaryEvidence(): void
    {
        $chapter = $this->authorizeEdit();
        try {
            $p = $this->summarySource;
            if ($p === []) {
                throw new \LogicException('当前没有可修订的摘要证据。');
            }
            app(ReviseBookSummaryEvidenceAction::class)->handle($chapter->id, $p['source'], $p['candidate'], $p['checksum'], $p['version'], $this->summaryRows, $p['key']);
            $this->summaryFeedback = '摘要证据修订已保存并完整复验，原摘要条目、正文、失败与费用保留。';
            $this->dispatch('book-events-saved');
        } catch (\Throwable $exception) {
            $this->summaryFeedback = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
        }
    }

    public function mount(int|string $record, int|string|null $chapter = null): void
    {
        parent::mount($record);
        $this->chapterId = $this->getRecord()->chapters()->findOrFail($chapter)->id;
        $this->loadCurrent();
        $this->loadEvents();
        $this->loadEvidenceRepair();
        $this->loadSummaryEvidence();
    }

    private function loadEvents(): void
    {
        $chapter = $this->authorizeEdit();
        $this->eventReviewId = null;
        $this->eventCandidateId = null;
        try {
            $action = app(ReviseBookChapterEventsAction::class);
            $source = $action->source($chapter);
            $candidate = $action->candidate($source);
            $this->eventRows = $action->rows($candidate);
            $this->eventReviewId = $source->id;
            $this->eventCandidateId = $candidate->id;
            $this->eventCandidateHash = $candidate->checksum;
            $this->eventControlVersion = NovelGenerationTask::findOrFail($source->generation_task_id)->control_version;
            $this->eventOperationKey = (string) Str::uuid();
        } catch (\Throwable) {
            // 只在已有当前提取或审校问题且无在途执行时展示编辑入口。
        }
    }

    public function saveEvents(): void
    {
        $chapter = $this->authorizeEdit();
        try {
            if (! $this->eventReviewId || ! $this->eventCandidateId) {
                throw new \LogicException('当前没有可修订的事件候选，请读取最新事件。');
            }
            app(ReviseBookChapterEventsAction::class)->handle($chapter->id, $this->eventReviewId, $this->eventCandidateId,
                $this->eventCandidateHash, $this->eventControlVersion, $this->eventRows, $this->eventOperationKey,
                additions: json_decode($this->eventAdditions, true, flags: JSON_THROW_ON_ERROR));
            $this->eventFeedback = '事件修订已保存，正文未改变；独立审校交接已登记，尚未正式采用。原候选、理由、次数与费用均保留。';
            $this->dispatch('book-events-saved');
        } catch (\Throwable $exception) {
            $this->eventFeedback = app(GenerationDiagnosticRedactor::class)->clean($exception->getMessage());
        } finally {
            $this->dispatch('book-edit-feedback', target: 'book-save-events');
        }
    }

    public function refreshEvents(): void
    {
        $rows = $this->eventRows;
        $old = $this->eventCandidateId;
        $this->loadEvents();
        if ($rows !== [] && $old !== $this->eventCandidateId) {
            $this->eventRows = $rows;
            $this->eventCandidateId = null;
            $this->eventFeedback = '事件来源已变化，原输入保留。请先复制修改内容，再重新打开当前章节对照，不能将旧事件按序号套用到新候选。';
        } else {
            $this->eventRows = $rows ?: $this->eventRows;
            $this->eventFeedback = '已核对当前事件来源，输入保留。';
        }
    }

    private function authorizeEdit(): Chapter
    {
        $this->authorizeAccess();
        abort_unless(NovelResource::canEdit($this->getRecord()), 403);

        return $this->getRecord()->chapters()->findOrFail($this->chapterId);
    }

    /** 打开、成功保存或显式对照时更新编辑身份；显式对照由调用者保留输入，轮询不得调用。 */
    private function loadCurrent(): void
    {
        $chapter = $this->authorizeEdit();
        $draft = GenerationArtifact::find($chapter->current_draft_artifact_id);
        $task = NovelGenerationTask::findOrFail($this->getRecord()->current_generation_task_id);
        $this->draftId = $draft?->id;
        $this->draftHash = $draft?->checksum ?? '';
        $this->body = $draft?->content ?? '';
        $this->controlVersion = $task->control_version;
        $this->operationKey = (string) Str::uuid();
    }

    public function saveDraft(): void
    {
        $chapter = $this->authorizeEdit();
        try {
            $this->validate(['body' => 'required|string|max:100000'], [
                'body.required' => '请填写新稿正文，原稿仍然保留。',
                'body.string' => '新稿正文必须是文本。',
                'body.max' => '新稿正文不能超过 100000 个字符，请保留输入后调整。',
            ]);
            if (! $this->draftId) {
                throw new \LogicException('当前还没有完整草稿，请等待写作完成。');
            }
            app(ReviseBookChapterDraftAction::class)->handle($chapter->id, $this->draftId, $this->draftHash, $this->controlVersion, $this->body, $this->operationKey);
            $this->loadCurrent();
            $this->dispatch('book-draft-saved');
            $this->feedback = '新稿已保存，重新提取与审校已登记。请查看任务状态；尚未正式采用。';
        } catch (\Throwable $e) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($e->getMessage());
        } finally {
            $this->dispatch('book-edit-feedback', target: 'book-save-draft');
        }
    }

    /** 用户主动对照最新稿时才更新 CAS；保留编辑文字，不自动提交或套用旧审校。 */
    public function refreshEditingSource(): void
    {
        $body = $this->body;
        $this->loadCurrent();
        $this->body = $body;
        $this->feedback = '已读取最新稿和任务状态，编辑文字已保留。请对照上方正文确认后，再保存新稿并复验。';
        $this->dispatch('book-edit-feedback', target: 'book-refresh-source');
    }

    public function getTitle(): string
    {
        return '章节阅读与复验';
    }

    /** 搜索从首屏开始，避免旧页码让有效结果看起来为空。 */
    public function updatedDirectorySearch(): void
    {
        $this->directoryPage = 1;
    }

    public function content(Schema $schema): Schema
    {
        // Livewire 动作会复用 Schema；延迟读取当前版本，保存后不能继续显示旧 PASS。
        return $schema->components([View::make('filament.resources.novels.pages.book-chapter')->viewData(fn (): array => $this->readingData())]);
    }

    private function readingData(): array
    {
        $chapter = $this->getRecord()->chapters()->findOrFail($this->chapterId);
        // 正式版优先；编辑仍绑定打开时的草稿 CAS，不随阅读切换改变。
        $draft = GenerationArtifact::find($this->version === 'canonical' && $chapter->canonical_artifact_id ? $chapter->canonical_artifact_id : $this->draftId);
        $review = $draft ? Review::whereHas('generationRun', fn ($q) => $q->where('execution_contract->draft_artifact_id', $draft->id))->latest('id')->first() : null;
        $previous = $draft ? GenerationArtifact::find($draft->source_manifest[0]['artifact_id'] ?? null) : null;
        // 局部改写先经过补丁产物，沿一层来源找到原全章，不把 JSON 当小说显示。
        if ($previous && ! isset($previous->data['word_count'])) {
            $previous = GenerationArtifact::find($previous->data['source_artifact_id'] ?? null);
        }

        return [
            'chapter' => $chapter, 'draft' => $draft, 'review' => $review, 'previous' => $previous,
            'eventCandidate' => $this->eventCandidateId ? GenerationArtifact::find($this->eventCandidateId) : null,
            'directory' => $this->getRecord()->chapters()->select(['id', 'sequence', 'title', 'canonical_artifact_id'])->when(trim($this->directorySearch) !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes(mb_substr($this->directorySearch, 0, 100), '%_\\').'%'))->orderBy('sequence')->paginate(25, ['*'], 'directory', max(1, $this->directoryPage)),
            'nearby' => $this->getRecord()->chapters()->whereIn('sequence', [$chapter->sequence - 1, $chapter->sequence + 1])->orderBy('sequence')->get(['id', 'sequence', 'title']),
            'stale' => $chapter->current_draft_artifact_id !== $this->draftId,
            'fragments' => $draft ? collect() : GenerationArtifact::where('type', 'chapter_fragment')->where('validation_status', 'validated')->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->id)->where('execution_contract->chapter_plan_id', $chapter->current_plan_id))->orderBy('id')->get(),
        ];
    }
}
