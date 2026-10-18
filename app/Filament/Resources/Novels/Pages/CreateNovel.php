<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Novels\CreateNovelGenerationTaskAction;
use App\Filament\Resources\Novels\NovelResource;
use App\Services\NovelBookWorkflow;
use Filament\Actions\Action;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/** 客户端重投共享提交标识；只有重新打开创建页才代表主动新建一本。 */
class CreateNovel extends CreateRecord
{
    protected static string $resource = NovelResource::class;

    protected static bool $canCreateAnother = false;

    #[Locked]
    public string $submissionKey;

    #[Locked]
    public bool $saveDraft = false;

    public function mount(): void
    {
        $this->submissionKey = (string) Str::uuid();
        parent::mount();
    }

    /** 小屏输入较长时保留可见主动作，不要求用户滚过高级选项。 */
    public function areFormActionsSticky(): bool
    {
        return true;
    }

    public function getPageClasses(): array
    {
        return [...parent::getPageClasses(), 'xn-novel-intake'];
    }

    public function getTitle(): string
    {
        return '从一个想法，开始一本小说';
    }

    public function getMaxContentWidth(): Width|string|null
    {
        return '3xl';
    }

    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateNovelGenerationTaskAction::class)->handle($this->submissionKey, $data['premise'], [...$data, 'save_draft' => $this->saveDraft])->novel;
    }

    protected function afterCreate(): void
    {
        if (! $this->saveDraft) {
            // 持久任务先提交；即使派发失败，扫描仍能从同一任务补派，不重复建书。
            $taskId = $this->record->current_generation_task_id;
            DB::afterCommit(fn () => app(NovelBookWorkflow::class)->advance($taskId));
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('开始生成');
    }

    protected function getFormActions(): array
    {
        return [$this->getCreateFormAction(), Action::make('saveDraft')->label('保存草稿')->color('gray')->action(function (): void {
            $this->saveDraft = true;
            try {
                $this->create();
            } finally {
                $this->saveDraft = false;
            }
        }), $this->getCancelFormAction()];
    }

    protected function getRedirectUrl(): string
    {
        return NovelResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
