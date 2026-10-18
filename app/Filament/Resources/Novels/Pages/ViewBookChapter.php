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

    /** 只在用户打开页面或成功保存后更新编辑身份，轮询不能覆盖未保存文字。 */
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
            $this->validate(['body' => 'required|string|max:100000']);
            if (! $this->draftId) {
                throw new \LogicException('当前还没有完整草稿，请等待写作完成。');
            }
            app(ReviseBookChapterDraftAction::class)->handle($chapter->id, $this->draftId, $this->draftHash, $this->controlVersion, $this->body, $this->operationKey);
            $this->loadCurrent();
            $this->feedback = '新稿已保存，重新提取与审校已登记。请查看任务状态；尚未正式采用。';
        } catch (\Throwable $e) {
            $this->feedback = app(GenerationDiagnosticRedactor::class)->clean($e->getMessage());
        }
    }

    public function getTitle(): string
    {
        return '章节阅读与复验';
    }

    public function content(Schema $schema): Schema
    {
        // Livewire 动作会复用 Schema；延迟读取当前版本，保存后不能继续显示旧 PASS。
        return $schema->components([View::make('filament.resources.novels.pages.book-chapter')->viewData(fn (): array => $this->readingData())]);
    }

    private function readingData(): array
    {
        $chapter = $this->getRecord()->chapters()->findOrFail($this->chapterId);
        $draft = GenerationArtifact::find($this->draftId);
        $review = $draft ? Review::whereHas('generationRun', fn ($q) => $q->where('execution_contract->draft_artifact_id', $draft->id))->latest('id')->first() : null;
        $previous = $draft ? GenerationArtifact::find($draft->source_manifest[0]['artifact_id'] ?? null) : null;
        // 局部改写先经过补丁产物，沿一层来源找到原全章，不把 JSON 当小说显示。
        if ($previous && ! isset($previous->data['word_count'])) {
            $previous = GenerationArtifact::find($previous->data['source_artifact_id'] ?? null);
        }

        return [
            'chapter' => $chapter, 'draft' => $draft, 'review' => $review, 'previous' => $previous,
            'stale' => $chapter->current_draft_artifact_id !== $this->draftId,
            'fragments' => $draft ? collect() : GenerationArtifact::where('type', 'chapter_fragment')->where('validation_status', 'validated')->whereHas('generationRun', fn ($q) => $q->where('chapter_id', $chapter->id)->where('execution_contract->chapter_plan_id', $chapter->current_plan_id))->orderBy('id')->get(),
        ];
    }
}
