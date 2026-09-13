<?php

namespace App\Services;

use App\Data\RewriteScopeDecision;
use App\Models\Chapter;
use App\Models\Review;
use App\Models\Scene;

final class RewriteScopeResolver
{
    /** @param array<int, array<string, mixed>> $findings */
    public function resolve(Chapter $chapter, array $findings): RewriteScopeDecision
    {
        $chapter->loadMissing('scenes.currentArtifact');
        $actionable = collect($findings)
            ->filter(fn (mixed $finding): bool => is_array($finding) && (bool) ($finding['auto_fixable'] ?? false));

        if ($actionable->isEmpty()) {
            return new RewriteScopeDecision('unresolved', null, 'no_auto_fixable_findings');
        }

        foreach ($actionable as $finding) {
            if ((bool) ($finding['requires_human_decision'] ?? false)) {
                return new RewriteScopeDecision('unresolved', null, 'finding_requires_human_decision');
            }

            $scope = $finding['scope'] ?? null;
            if (! in_array($scope, ['paragraph', 'scene', 'chapter'], true)) {
                return new RewriteScopeDecision('unresolved', null, 'unsupported_finding_scope');
            }

            if ($scope === 'chapter' && ($finding['scene_id'] ?? null) !== null) {
                return new RewriteScopeDecision('unresolved', null, 'chapter_finding_has_scene_reference');
            }
        }

        if ($actionable->contains(fn (array $finding): bool => $finding['scope'] === 'chapter')) {
            return new RewriteScopeDecision(
                'chapter',
                null,
                'chapter_finding_present',
                $actionable->keys()->map(fn (int|string $index): int => (int) $index)->values()->all(),
            );
        }

        $sceneIds = [];
        $findingIndexes = [];

        foreach ($actionable as $index => $finding) {
            $scope = $finding['scope'];

            $sceneId = $this->sceneIdForFinding($chapter, $finding);

            if ($sceneId === null) {
                return new RewriteScopeDecision('unresolved', null, $scope === 'paragraph'
                    ? 'paragraph_evidence_not_unique'
                    : 'invalid_scene_reference');
            }

            $sceneIds[] = $sceneId;
            $findingIndexes[] = (int) $index;
        }

        $sceneIds = array_values(array_unique($sceneIds));

        if (count($sceneIds) > 1) {
            return new RewriteScopeDecision(
                'chapter',
                null,
                'multiple_scenes_affected',
                $findingIndexes,
            );
        }

        if (count($sceneIds) === 1) {
            return new RewriteScopeDecision('scene', $sceneIds[0], 'single_scene_affected', $findingIndexes);
        }

        return new RewriteScopeDecision('unresolved', null, 'rewrite_scope_not_found');
    }

    public function resolveReview(Chapter $chapter, Review $review): RewriteScopeDecision
    {
        $stored = data_get($review->artifact?->data, 'rewrite_scope');

        if ($stored === null) {
            return $this->resolve($chapter, $review->findings);
        }

        if (! is_array($stored)) {
            return new RewriteScopeDecision('unresolved', null, 'invalid_persisted_rewrite_scope');
        }

        $scope = $stored['scope'] ?? null;
        $sceneId = $stored['scene_id'] ?? null;
        $reason = is_string($stored['reason'] ?? null) ? $stored['reason'] : 'persisted_review_scope';
        $findingIndexes = is_array($stored['finding_indexes'] ?? null)
            ? array_values(array_filter($stored['finding_indexes'], 'is_int'))
            : [];

        if ($scope === 'chapter' && $sceneId === null) {
            return new RewriteScopeDecision('chapter', null, $reason, $findingIndexes);
        }

        if ($scope === 'scene' && is_int($sceneId) && $chapter->scenes()->whereKey($sceneId)->exists()) {
            return new RewriteScopeDecision('scene', $sceneId, $reason, $findingIndexes);
        }

        return new RewriteScopeDecision('unresolved', null, 'invalid_persisted_rewrite_scope');
    }

    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public function findingsForScene(Chapter $chapter, array $findings, int $sceneId): array
    {
        $chapter->loadMissing('scenes.currentArtifact');

        return collect($findings)
            ->filter(function (mixed $finding) use ($chapter, $sceneId): bool {
                if (! is_array($finding) || ! in_array($finding['scope'] ?? null, ['scene', 'paragraph'], true)) {
                    return false;
                }

                return $this->sceneIdForFinding($chapter, $finding) === $sceneId;
            })
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $finding */
    private function sceneIdForFinding(Chapter $chapter, array $finding): ?int
    {
        $sceneId = $finding['scene_id'] ?? null;

        if (is_int($sceneId)) {
            return $chapter->scenes->contains(fn (Scene $scene): bool => $scene->getKey() === $sceneId)
                ? $sceneId
                : null;
        }

        if (($finding['scope'] ?? null) !== 'paragraph') {
            return null;
        }

        $evidence = $finding['evidence'] ?? null;
        if (! is_string($evidence) || trim($evidence) === '') {
            return null;
        }

        $matches = $chapter->scenes
            ->filter(fn (Scene $scene): bool => is_string($scene->currentArtifact?->content)
                && str_contains($scene->currentArtifact->content, $evidence));

        return $matches->count() === 1 ? (int) $matches->first()->getKey() : null;
    }
}
