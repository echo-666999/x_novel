<?php

use App\Data\CanonicalCommitData;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Review;
use App\Services\CanonicalCommitService;
use App\Services\GenerationRunCoordinator;
use App\Support\IsolatedTestEnvironment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/NovelPipelineFixtures.php';

/** 两个真实数据库会话验证提交与暂停的串行化，不用模拟锁代替。 */
test('two postgres sessions serialize canonical commit and pause with one formal result', function () {
    expect(DB::scalar('SELECT current_database()'))->toBe(IsolatedTestEnvironment::DATABASE);
    Artisan::call('migrate:fresh', ['--force' => true]);
    $task = pipelineTask();
    Queue::fake();
    pipelineUntil($task, 'review');
    pipelineStep($task);
    $task->refresh()->update(['status' => 'running']);
    $review = Review::sole();
    $r = $review->artifact->data;
    $draft = GenerationArtifact::findOrFail($r['source_artifact_id']);
    $data = new CanonicalCommitData(Chapter::sole()->id, $draft->id, $review->id, $r['event_artifact_id'], $r['patch_artifact_id'], 0, $draft->checksum, $task->id, $task->control_version);
    config(['database.connections.commit_contender' => config('database.connections.pgsql')]);
    $first = DB::connection('pgsql');
    $second = DB::connection('commit_contender');
    $second->statement("SET lock_timeout='100ms'");
    $first->beginTransaction();
    try {
        app(CanonicalCommitService::class)->commit($data);
        DB::setDefaultConnection('commit_contender');
        expect(fn () => app(CanonicalCommitService::class)->commit($data))->toThrow(QueryException::class);
        expect(fn () => app(GenerationRunCoordinator::class)->control($task->id, $task->control_version, 'pause'))->toThrow(QueryException::class);
        $first->commit();
        app(CanonicalCommitService::class)->commit($data);
        expect(Chapter::where('status', 'canonical')->count())->toBe(1);
        expect(GenerationRun::where('stage', 'commit')->count())->toBe(1);
        expect($task->novel->storyStateVersions()->count())->toBe(2);
        app(GenerationRunCoordinator::class)->control($task->id, $task->control_version, 'pause');
        expect(fn () => app(CanonicalCommitService::class)->commit($data))->toThrow(LogicException::class);
    } finally {
        DB::setDefaultConnection('pgsql');
        if ($first->transactionLevel()) {
            $first->rollBack();
        }
        DB::purge('commit_contender');
        Artisan::call('migrate:fresh', ['--force' => true]);
    }
});
