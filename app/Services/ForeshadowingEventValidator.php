<?php

namespace App\Services;

use App\Data\StoryEventCandidate;
use App\Enums\EventType;
use App\Enums\ForeshadowingPlanAction;
use App\Enums\ForeshadowingStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;

final class ForeshadowingEventValidator
{
    /**
     * A fulfilled Assembly Coverage quote is already validated against the final Chapter Draft.
     * Attach it to the matching event so different, complementary model quotes cannot sever
     * the event from the exact evidence that passed the frozen action contract.
     *
     * @param  array<int, StoryEventCandidate>  $events
     * @param  array<string, mixed>  $contract
     * @return array<int, StoryEventCandidate>
     */
    public function attachCoverageEvidence(Chapter $chapter, GenerationArtifact $draft, array $events, array $contract): array
    {
        $actions = $this->actions($chapter, $draft, $contract);
        $lastActionIndex = [];

        return collect($events)->map(function (StoryEventCandidate $event) use ($actions, $draft, &$lastActionIndex): StoryEventCandidate {
            if (! $event->eventType->isForeshadowing() || $event->subjectId === null) {
                return $event;
            }

            $foreshadowingId = (int) $event->subjectId;
            $expectedAction = $this->actionForEvent($event->eventType);
            $actionIndex = $this->matchingActionIndex(
                $actions,
                $foreshadowingId,
                $expectedAction,
                $lastActionIndex[$foreshadowingId] ?? -1,
            );

            if ($actionIndex === null) {
                return $event;
            }

            $lastActionIndex[$foreshadowingId] = $actionIndex;
            $action = $actions[$actionIndex];
            $coverageEvidence = $action['coverage_evidence'] ?? null;
            $targetSceneId = (int) ($action['target_scene_id'] ?? 0);

            if (($action['coverage_status'] ?? null) !== 'fulfilled'
                || ! is_string($coverageEvidence)
                || trim($coverageEvidence) === ''
                || $targetSceneId < 1
                || ! str_contains((string) $draft->content, $coverageEvidence)) {
                return $event;
            }

            $evidence = collect($event->evidence);
            $alreadyAttached = $evidence->contains(fn (array $item): bool => (int) ($item['scene_id'] ?? 0) === $targetSceneId
                && ($item['quote'] ?? null) === $coverageEvidence);

            if (! $alreadyAttached) {
                $evidence->push([
                    'artifact_id' => $draft->getKey(),
                    'scene_id' => $targetSceneId,
                    'quote' => $coverageEvidence,
                    'start_offset' => null,
                    'end_offset' => null,
                ]);
            }

            return StoryEventCandidate::fromArray([
                ...$event->toArray(),
                'evidence' => $evidence->values()->all(),
            ]);
        })->all();
    }

    /**
     * @param  array<int, StoryEventCandidate>  $events
     * @param  array<string, mixed>  $contract
     * @return array<int, array{event_index: int, code: string, message: string, related_state_path: string|null, metadata: array<string, mixed>}>
     */
    public function violations(Chapter $chapter, GenerationArtifact $draft, array $events, array $contract): array
    {
        $actions = $this->actions($chapter, $draft, $contract);
        $statuses = [];
        $lastActionIndex = [];
        $violations = [];

        foreach ($events as $eventIndex => $event) {
            if (! $event->eventType->isForeshadowing() || $event->subjectId === null) {
                continue;
            }

            $foreshadowingId = (int) $event->subjectId;
            $expectedAction = $this->actionForEvent($event->eventType);
            $actionIndex = $this->matchingActionIndex(
                $actions,
                $foreshadowingId,
                $expectedAction,
                $lastActionIndex[$foreshadowingId] ?? -1,
            );

            if ($expectedAction === null || $actionIndex === null) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_EVENT_NOT_AUTHORIZED',
                    "伏笔 #{$foreshadowingId} 的 {$event->eventType->value} 事件不在本章冻结动作契约中。",
                    $foreshadowingId,
                    ['event_type' => $event->eventType->value],
                );

                continue;
            }

            $action = $actions[$actionIndex];
            $lastActionIndex[$foreshadowingId] = $actionIndex;

            if (blank($action['acceptance_criteria'] ?? null)) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_ACCEPTANCE_CRITERIA_MISSING',
                    "伏笔 #{$foreshadowingId} 的 {$expectedAction->value} 动作缺少验收条件。",
                    $foreshadowingId,
                    ['action' => $expectedAction->value],
                );
            }

            if ($expectedAction === ForeshadowingPlanAction::Abandon
                && ! $this->hasFrozenManualAuthorization($action, $contract)) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_ACTION_REQUIRES_USER_AUTHORIZATION',
                    "伏笔 #{$foreshadowingId} 的 abandon 事件缺少与冻结 State Version 一致的人工授权。",
                    $foreshadowingId,
                    ['action' => $expectedAction->value],
                );
            }

            if (($action['coverage_status'] ?? null) !== 'fulfilled') {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_COVERAGE_NOT_FULFILLED',
                    "伏笔 #{$foreshadowingId} 的 {$expectedAction->value} 动作没有通过当前草稿 Coverage，不能生成正式事件候选。",
                    $foreshadowingId,
                    [
                        'action' => $expectedAction->value,
                        'coverage_status' => $action['coverage_status'] ?? null,
                        'target_scene_sequence' => $action['target_scene_sequence'] ?? null,
                    ],
                );
            } elseif (! $this->evidenceOverlaps(
                $event,
                $action['coverage_evidence'] ?? null,
                (int) ($action['target_scene_id'] ?? 0),
            )) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_EVENT_EVIDENCE_MISMATCH',
                    "伏笔 #{$foreshadowingId} 的事件证据没有覆盖已通过验收的动作证据。",
                    $foreshadowingId,
                    [
                        'action' => $expectedAction->value,
                        'coverage_evidence' => $action['coverage_evidence'] ?? null,
                        'target_scene_id' => $action['target_scene_id'] ?? null,
                    ],
                );
            }

            $status = $statuses[$foreshadowingId]
                ?? ForeshadowingStatus::tryFrom((string) ($action['content_status'] ?? ''));
            $statusSource = $action['content_status_source'] ?? null;
            $statusIsAuthoritative = $statusSource === 'canonical_state'
                || ($statusSource === 'domain_projection' && $status === ForeshadowingStatus::Idea);
            $nextStatus = $status === null || ! $statusIsAuthoritative
                ? null
                : $this->nextStatus($status, $event->eventType);

            if ($status === null || $status === ForeshadowingStatus::Due || ! $statusIsAuthoritative) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_LIFECYCLE_UNKNOWN',
                    "伏笔 #{$foreshadowingId} 缺少可验证的 Canonical 内容生命周期状态。",
                    $foreshadowingId,
                    [
                        'content_status' => $action['content_status'] ?? null,
                        'content_status_source' => $statusSource,
                    ],
                );
            } elseif ($nextStatus === null) {
                $violations[] = $this->violation(
                    $eventIndex,
                    'FORESHADOWING_LIFECYCLE_INVALID',
                    "伏笔 #{$foreshadowingId} 不能从 {$status->value} 执行 {$event->eventType->value}。",
                    $foreshadowingId,
                    ['from_status' => $status->value, 'event_type' => $event->eventType->value],
                );
            } else {
                $statuses[$foreshadowingId] = $nextStatus;
            }
        }

        return $violations;
    }

    /** @return array<int, array<string, mixed>> */
    private function actions(Chapter $chapter, GenerationArtifact $draft, array $contract): array
    {
        $coverageByScene = collect(data_get($draft->data, 'scene_coverage', []))
            ->filter(fn (mixed $row): bool => is_array($row))
            ->keyBy(fn (array $row): int => (int) ($row['scene_id'] ?? 0));
        $scenesBySequence = $chapter->scenes()->get(['id', 'sequence'])->keyBy('sequence');
        $localIndexes = [];

        return collect($contract['actions'] ?? [])->map(function (mixed $item) use ($coverageByScene, $scenesBySequence, &$localIndexes): array {
            if (! is_array($item)) {
                return [];
            }

            $sceneSequence = (int) data_get($item, 'plan_action.target_scene_sequence');
            $sceneId = (int) ($scenesBySequence->get($sceneSequence)?->getKey() ?? 0);
            $localIndex = $localIndexes[$sceneSequence] ?? 0;
            $localIndexes[$sceneSequence] = $localIndex + 1;
            $coverage = data_get($coverageByScene->get($sceneId), "foreshadowing_coverage.{$localIndex}");
            $foreshadowingId = (int) ($item['foreshadowing_id'] ?? 0);
            $action = (string) data_get($item, 'plan_action.action');

            $coverageMatches = is_array($coverage)
                && (int) ($coverage['foreshadowing_id'] ?? 0) === $foreshadowingId
                && ($coverage['action'] ?? null) === $action;

            return [
                'foreshadowing_id' => $foreshadowingId,
                'action' => $action,
                'content_status' => $item['content_status'] ?? null,
                'content_status_source' => $item['content_status_source'] ?? null,
                'target_scene_sequence' => $sceneSequence,
                'target_scene_id' => $sceneId,
                'acceptance_criteria' => data_get($item, 'plan_action.acceptance_criteria'),
                'plan_action' => $item['plan_action'] ?? [],
                'coverage_status' => $coverageMatches ? ($coverage['status'] ?? null) : null,
                'coverage_evidence' => $coverageMatches ? ($coverage['evidence'] ?? null) : null,
            ];
        })->values()->all();
    }

    /** @param array<string, mixed> $action @param array<string, mixed> $contract */
    private function hasFrozenManualAuthorization(array $action, array $contract): bool
    {
        $planAction = is_array($action['plan_action'] ?? null) ? $action['plan_action'] : [];
        $userId = filter_var($planAction['authorized_by_user_id'] ?? null, FILTER_VALIDATE_INT);
        $authorizedStateVersion = filter_var($planAction['authorized_at_state_version'] ?? null, FILTER_VALIDATE_INT);

        return filled($planAction['reason'] ?? null)
            && $userId !== false
            && $userId > 0
            && filled($planAction['authorized_at'] ?? null)
            && filter_var($planAction['authorized_at_canonical_chapter'] ?? null, FILTER_VALIDATE_INT) !== false
            && $authorizedStateVersion !== false
            && $authorizedStateVersion === (int) ($contract['state_version'] ?? -1);
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function matchingActionIndex(array $actions, int $foreshadowingId, ?ForeshadowingPlanAction $expected, int $after): ?int
    {
        if ($expected === null) {
            return null;
        }

        foreach ($actions as $index => $action) {
            if ($index > $after
                && ($action['foreshadowing_id'] ?? null) === $foreshadowingId
                && ($action['action'] ?? null) === $expected->value) {
                return $index;
            }
        }

        return null;
    }

    private function actionForEvent(EventType $eventType): ?ForeshadowingPlanAction
    {
        return match ($eventType) {
            EventType::ForeshadowingPlanted => ForeshadowingPlanAction::Plant,
            EventType::ForeshadowingReinforced => ForeshadowingPlanAction::Reinforce,
            EventType::ForeshadowingPaidOff => ForeshadowingPlanAction::PayOff,
            EventType::ForeshadowingAbandoned => ForeshadowingPlanAction::Abandon,
            default => null,
        };
    }

    private function nextStatus(ForeshadowingStatus $status, EventType $eventType): ?ForeshadowingStatus
    {
        if ($status->isTerminal()) {
            return null;
        }

        return match ($eventType) {
            EventType::ForeshadowingPlanted => $status === ForeshadowingStatus::Idea ? ForeshadowingStatus::Planted : null,
            EventType::ForeshadowingReinforced => in_array($status, [ForeshadowingStatus::Planted, ForeshadowingStatus::Reinforced], true)
                ? ForeshadowingStatus::Reinforced
                : null,
            EventType::ForeshadowingPaidOff => in_array($status, [ForeshadowingStatus::Planted, ForeshadowingStatus::Reinforced], true)
                ? ForeshadowingStatus::PaidOff
                : null,
            EventType::ForeshadowingAbandoned => ForeshadowingStatus::Abandoned,
            default => null,
        };
    }

    private function evidenceOverlaps(StoryEventCandidate $event, mixed $coverageEvidence, int $targetSceneId): bool
    {
        if (! is_string($coverageEvidence) || trim($coverageEvidence) === '') {
            return false;
        }

        return collect($event->evidence)->contains(function (array $evidence) use ($coverageEvidence, $targetSceneId): bool {
            $quote = $evidence['quote'] ?? null;

            return is_string($quote)
                && (int) ($evidence['scene_id'] ?? 0) === $targetSceneId
                && (str_contains($quote, $coverageEvidence) || str_contains($coverageEvidence, $quote));
        });
    }

    /** @return array{event_index: int, code: string, message: string, related_state_path: string, metadata: array<string, mixed>} */
    private function violation(int $eventIndex, string $code, string $message, int $foreshadowingId, array $metadata): array
    {
        return [
            'event_index' => $eventIndex,
            'code' => $code,
            'message' => $message,
            'related_state_path' => "foreshadowings.{$foreshadowingId}.status",
            'metadata' => $metadata,
        ];
    }
}
