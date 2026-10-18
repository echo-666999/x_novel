<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Chapters\ReviseBookChapterDraftAction;
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

    public function mount(int|string $record, int|string|null $chapter = null): void
    {
        parent::mount($record);
        $this->chapterId = $this->getRecord()->chapters()->findOrFail($chapter)->id;
        $this->loadCurrent();
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
            'directory' => $this->getRecord()->chapters()->select(['id', 'sequence', 'title', 'canonical_artifact_id'])->when(trim($this->directorySearch) !== '', fn ($q) => $q->where('title', 'like', '%'.addcslashes(mb_substr($this->directorySearch, 0, 100), '%_\\').'%'))->orderBy('sequence')->paginate(25, ['*'], 'directory', max(1, $this->directoryPage)),
            'nearby' => $this->getRecord()->chapters()->whereIn('sequence', [$chapter->sequence - 1, $chapter->sequence + 1])->orderBy('sequence')->get(['id', 'sequence', 'title']),
            'stale' => $chapter->current_draft_artifact_id !== $this->draftId,
            'fragments' => $draft ? collect() : GenerationArtifact::where('type', 'chapter_fragment')->where('validation_status', 'validated')->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->id)->where('execution_contract->chapter_plan_id', $chapter->current_plan_id))->orderBy('id')->get(),
        ];
    }
}
