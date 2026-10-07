<?php

namespace App\Actions\Novels;

use App\Data\NormalizedNovelOutline;
use App\Enums\NovelOutlineSource;
use App\Enums\NovelOutlineStatus;
use App\Enums\RunStatus;
use App\Models\Novel;
use App\Models\NovelOutline;
use App\Models\User;
use App\Services\NovelOutlineChecksum;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 只修订一个 Outline 节点，并继续通过不可变版本保存完整规划。
 */
class ReviseNovelOutlineNodeAction
{
    /** @var array<string, array<int, string>> */
    private const ALLOWED_FIELDS = [
        'volume' => ['title'],
        'arc' => ['title', 'goal', 'stakes', 'completion_conditions'],
        'beat' => ['title', 'summary', 'chapter_budget', 'acceptance_criteria', 'must_include', 'must_not_include'],
        'milestone' => ['title', 'objective', 'acceptance_criteria', 'must_include', 'must_not_include'],
    ];

    public function __construct(
        private readonly CreateNormalizedNovelOutlineVersionAction $createVersion,
        private readonly ApplyNovelOutlineRevisionAction $applyRevision,
        private readonly NovelOutlineChecksum $checksum,
    ) {}

    /**
     * @param  array<string, mixed>  $changes
     */
    public function handle(
        Novel $novel,
        int $expectedOutlineId,
        string $expectedOutlineChecksum,
        string $nodeType,
        string $nodeKey,
        array $changes,
        ?User $creator = null,
    ): NovelOutline {
        $this->assertSupportedNodeType($nodeType);

        $outline = $novel->outlines()
            ->with('volumes.arcs.beats.milestones', 'volumes.arcs.beats.handoffNextBeat')
            ->find($expectedOutlineId);
        if ($outline === null) {
            throw ValidationException::withMessages(['outline' => '目标 Outline Version 不属于当前小说或已不存在。']);
        }

        $updated = $this->replaceNode(
            NormalizedNovelOutline::fromModel($outline)->toArray(),
            $nodeType,
            $nodeKey,
            $changes,
        );

        if ($outline->status === NovelOutlineStatus::Current) {
            // Current 修订继续复用正式保护边界，不能绕过已冻结 Plan 或 Canonical Event。
            return $this->applyRevision->handle(
                novel: $novel,
                outline: $updated,
                expectedCurrentOutlineId: $expectedOutlineId,
                expectedCurrentChecksum: $expectedOutlineChecksum,
                creator: $creator,
            );
        }

        if ($outline->status !== NovelOutlineStatus::Draft) {
            throw ValidationException::withMessages(['outline' => '只能修订当前 Draft 或 Current Outline。']);
        }

        return DB::transaction(function () use ($novel, $expectedOutlineId, $expectedOutlineChecksum, $nodeType, $nodeKey, $changes, $creator): NovelOutline {
            $lockedNovel = Novel::query()->lockForUpdate()->findOrFail($novel->getKey());
            $base = $lockedNovel->outlines()
                ->lockForUpdate()
                ->with('volumes.arcs.beats.milestones', 'volumes.arcs.beats.handoffNextBeat')
                ->find($expectedOutlineId);

            if ($base === null || $base->status !== NovelOutlineStatus::Draft) {
                throw ValidationException::withMessages(['outline' => 'Draft Outline 已变化，请重新载入后再编辑。']);
            }
            if ($lockedNovel->outlines()->where('status', NovelOutlineStatus::Draft->value)->latest('version')->value('id') !== $base->getKey()
                || ! hash_equals($base->checksum, $expectedOutlineChecksum)
                || ! hash_equals($base->checksum, $this->checksum->for($base))) {
                throw ValidationException::withMessages(['outline_checksum' => 'Draft Outline 已变化，请重新载入后再编辑。']);
            }
            if ($lockedNovel->generationRuns()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->exists()) {
                throw ValidationException::withMessages(['generation_runs' => '小说仍有排队中或运行中的 Generation Run；请等待结束后再编辑大纲。']);
            }

            $updated = $this->replaceNode(
                NormalizedNovelOutline::fromModel($base)->toArray(),
                $nodeType,
                $nodeKey,
                $changes,
            );
            if (hash_equals($base->checksum, $this->checksum->for($updated))) {
                return $base;
            }

            return $this->createVersion->handle(
                novel: $lockedNovel,
                outline: $updated,
                source: NovelOutlineSource::Revision,
                basedOn: $base,
                creator: $creator,
            );
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $outline
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function replaceNode(array $outline, string $nodeType, string $nodeKey, array $changes): array
    {
        $allowedFields = self::ALLOWED_FIELDS[$nodeType];
        if (array_diff(array_keys($changes), $allowedFields) !== []) {
            throw ValidationException::withMessages(['outline_node' => '节点修订包含不允许修改的字段。']);
        }

        $found = false;
        foreach ($outline['volumes'] as &$volume) {
            if ($nodeType === 'volume' && ($volume['key'] ?? null) === $nodeKey) {
                $volume = [...$volume, ...$changes];
                $found = true;
            }
            foreach ($volume['arcs'] as &$arc) {
                if ($nodeType === 'arc' && ($arc['key'] ?? null) === $nodeKey) {
                    $arc = [...$arc, ...$changes];
                    $found = true;
                }
                foreach ($arc['beats'] as &$beat) {
                    if ($nodeType === 'beat' && ($beat['key'] ?? null) === $nodeKey) {
                        $beat = [...$beat, ...$changes];
                        $found = true;
                    }
                    foreach ($beat['milestones'] as &$milestone) {
                        if ($nodeType === 'milestone' && ($milestone['key'] ?? null) === $nodeKey) {
                            $milestone = [...$milestone, ...$changes];
                            $found = true;
                        }
                    }
                    unset($milestone);
                }
                unset($beat);
            }
            unset($arc);
        }
        unset($volume);

        if (! $found) {
            throw ValidationException::withMessages(['outline_node' => "Outline 中不存在 {$nodeType} 节点 {$nodeKey}。"]);
        }

        return $outline;
    }

    private function assertSupportedNodeType(string $nodeType): void
    {
        if (! array_key_exists($nodeType, self::ALLOWED_FIELDS)) {
            throw ValidationException::withMessages(['outline_node' => '不支持的 Outline 节点类型。']);
        }
    }
}
