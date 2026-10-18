<?php

namespace App\Filament\Resources\Novels\Pages;

use App\Actions\Novels\ReviseNovelIntentAction;
use App\AI\AiModelRouteService;
use App\Filament\Resources\Novels\NovelResource;
use App\Filament\Resources\Novels\Schemas\NovelForm;
use App\Models\GenerationArtifact;
use App\Models\NovelGenerationTask;
use App\Services\GenerationCostPolicy;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

class EditNovel extends EditRecord
{
    protected static string $resource = NovelResource::class;

    #[Locked]
    public int $taskControlVersion = 0;

    /** 新任务通过领域动作保存版本，页面不直接修改正式数据。 */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->current_generation_task_id) {
            try {
                return app(ReviseNovelIntentAction::class)->handle($record->current_generation_task_id, $this->taskControlVersion, $data['premise'], $data);
            } catch (\LogicException $exception) {
                throw ValidationException::withMessages(['data.premise' => $exception->getMessage()]);
            }
        }

        return parent::handleRecordUpdate($record, $data);
    }

    protected function afterSave(): void
    {
        // 成功保存后刷新页面控制版本，失败时保留原表单与旧版本等待用户核对。
        if ($this->getRecord()->current_generation_task_id) {
            $this->taskControlVersion = NovelGenerationTask::findOrFail($this->getRecord()->current_generation_task_id)->control_version;
        }
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
        return '小说工作台';
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if ($this->getRecord()->current_generation_task_id) {
            $task = NovelGenerationTask::findOrFail($this->getRecord()->current_generation_task_id);
            $this->taskControlVersion = $task->control_version;
            $intent = GenerationArtifact::findOrFail($task->intent_artifact_id)->data;

            return ['premise' => $intent['premise'], ...$intent['explicit'], 'mode' => $task->mode, 'opening_chapter_count' => $task->opening_chapter_count, 'budget_limits' => data_get($data, 'settings.budget', [])];
        }
        $data = NovelForm::prepareGenreForFill($data);
        // 表单状态使用价格 ID 或受控保留 Token，避免把 Provider/Model 明文拆成两个可漂移字段。
        $data['ai_model_overrides'] = app(AiModelRouteService::class)->novelOverrideFormState($this->getRecord());
        $data['budget_limits'] = data_get($data, 'settings.budget', []);
        $data['generation_chapter_target_words'] = (int) data_get($data, 'settings.generation.chapter_target_words', 3_000);

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($this->getRecord()->current_generation_task_id) {
            return $data;
        }
        $data = NovelForm::prepareGenreForPersistence($data);
        $settings = $this->getRecord()->settings ?? [];
        $chapterTargetWords = (int) $data['generation_chapter_target_words'];
        if (array_key_exists('generation', $settings) || $chapterTargetWords !== 3_000) {
            $settings['generation']['chapter_target_words'] = $chapterTargetWords;
        }
        // 合并时保留无关 settings；旧 Outline Model-only 值只有用户重选完整路由后才允许迁移。
        $settings = app(AiModelRouteService::class)->applyNovelOverrides(
            $settings,
            (array) ($data['ai_model_overrides'] ?? []),
            $this->getRecord(),
        );
        // 与创建入口一致；关闭上限保留金额含义但不再阻断。
        $settings = app(GenerationCostPolicy::class)->apply($settings, (array) ($data['budget_limits'] ?? []), auth()->id());

        $data['settings'] = $settings;
        unset($data['ai_model_overrides'], $data['budget_limits'], $data['generation_chapter_target_words']);

        return $data;
    }
}
