<?php

namespace App\Http\Controllers;

use App\Enums\ArtifactType;
use App\Filament\Resources\Novels\NovelResource;
use App\Models\GenerationArtifact;
use App\Models\NovelGenerationTask;
use App\Services\BookExportService;
use Illuminate\Support\Facades\Storage;

/** 私有下载再次验证后台权限；不能通过猜测产物编号读取正文。 */
class BookExportController extends Controller
{
    public function __invoke(GenerationArtifact $artifact)
    {
        $task = NovelGenerationTask::findOrFail($artifact->generationRun->generation_task_id);
        abort_unless($artifact->type === ArtifactType::ExportManifest && $artifact->novel_id === $task->novel_id && NovelResource::canView($task->novel), 403);
        $path = app(BookExportService::class)->file($artifact);

        // 文件名不使用用户正文，避免换行和路径字符进入响应头。
        return Storage::disk('local')->download($path, 'novel-'.$task->novel_id.'-'.$artifact->id.'.txt', ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }
}
