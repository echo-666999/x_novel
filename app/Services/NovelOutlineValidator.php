<?php

namespace App\Services;

use App\Data\NovelOutlineValidationResult;
use App\Enums\StoryArcType;
use App\Enums\WorldEntityType;

class NovelOutlineValidator
{
    /** @param array<string, mixed> $content */
    public function validate(array $content): NovelOutlineValidationResult
    {
        $errors = [];
        $keys = [];
        $candidateKeys = [];
        $beatKeys = [];
        $mainArcCount = 0;

        $this->requiredText($content, 'title', 'Outline', $errors);
        $this->requiredText($content, 'summary', 'Outline', $errors);
        $this->stringList($content['must_include'] ?? [], 'Outline must_include', $errors);
        $this->stringList($content['must_not_include'] ?? [], 'Outline must_not_include', $errors);

        $volumes = $content['volumes'] ?? null;
        if (! is_array($volumes) || $volumes === []) {
            $errors[] = 'Outline 至少需要一个 Volume。';
            $volumes = [];
        }
        $this->continuousSequences($volumes, 'Volume', $errors);

        foreach (array_values($volumes) as $volumeIndex => $volume) {
            $path = 'Volume '.($volumeIndex + 1);
            if (! is_array($volume)) {
                $errors[] = "{$path} 结构无效。";

                continue;
            }
            $this->nodeKey($volume, $path, $keys, $errors);
            foreach (['title', 'goal', 'climax'] as $field) {
                $this->requiredText($volume, $field, $path, $errors);
            }
            if (! is_int($volume['target_words'] ?? null) || $volume['target_words'] < 1) {
                $errors[] = "{$path} target_words 必须是正整数。";
            }

            $arcs = $volume['arcs'] ?? null;
            if (! is_array($arcs) || $arcs === []) {
                $errors[] = "{$path} 至少需要一个 Arc。";
                $arcs = [];
            }
            $this->continuousSequences($arcs, "{$path} Arc", $errors);

            foreach (array_values($arcs) as $arcIndex => $arc) {
                $arcPath = "{$path} Arc ".($arcIndex + 1);
                if (! is_array($arc)) {
                    $errors[] = "{$arcPath} 结构无效。";

                    continue;
                }
                $this->nodeKey($arc, $arcPath, $keys, $errors);
                foreach (['title', 'goal', 'stakes'] as $field) {
                    $this->requiredText($arc, $field, $arcPath, $errors);
                }
                $type = StoryArcType::tryFrom((string) ($arc['type'] ?? ''));
                if ($type === null) {
                    $errors[] = "{$arcPath} type 必须是 main 或 subplot。";
                } elseif ($type === StoryArcType::Main) {
                    $mainArcCount++;
                }
                $this->stringList($arc['completion_conditions'] ?? [], "{$arcPath} completion_conditions", $errors);

                $beats = $arc['beats'] ?? null;
                if (! is_array($beats) || ($type === StoryArcType::Main && $beats === [])) {
                    $errors[] = "{$arcPath} 的 Main Arc 至少需要一个 Beat。";
                    $beats = [];
                }
                $this->continuousSequences($beats, "{$arcPath} Beat", $errors);
                foreach (array_values($beats) as $beatIndex => $beat) {
                    $beatPath = "{$arcPath} Beat ".($beatIndex + 1);
                    if (! is_array($beat)) {
                        $errors[] = "{$beatPath} 必须是结构化对象。";

                        continue;
                    }
                    $key = $this->nodeKey($beat, $beatPath, $keys, $errors);
                    if ($key !== '') {
                        $beatKeys[$key] = true;
                    }
                    foreach (['title', 'summary'] as $field) {
                        $this->requiredText($beat, $field, $beatPath, $errors);
                    }
                    $this->budget($beat['chapter_budget'] ?? null, $beatPath, $errors);
                    $criteria = $beat['acceptance_criteria'] ?? null;
                    if (! is_array($criteria) || $criteria === []) {
                        $errors[] = "{$beatPath} acceptance_criteria 至少需要一项。";
                    } else {
                        $this->stringList($criteria, "{$beatPath} acceptance_criteria", $errors);
                    }
                    $must = $this->stringList($beat['must_include'] ?? [], "{$beatPath} must_include", $errors);
                    $mustNot = $this->stringList($beat['must_not_include'] ?? [], "{$beatPath} must_not_include", $errors);
                    if (array_intersect($must, $mustNot) !== []) {
                        $errors[] = "{$beatPath} 同一文本不能同时出现在 must_include 和 must_not_include。";
                    }
                    $this->candidates($beat['character_candidates'] ?? [], 'Character', $beatPath, $candidateKeys, $errors);
                    $this->candidates($beat['world_entity_candidates'] ?? [], 'World Entity', $beatPath, $candidateKeys, $errors);
                }
            }
        }

        if ($mainArcCount === 0) {
            $errors[] = 'Outline 至少需要一个 Main Arc。';
        }
        $this->baseline($content['baseline_completions'] ?? [], $beatKeys, $errors);

        return new NovelOutlineValidationResult(array_values(array_unique($errors)));
    }

    /** @param array<string, mixed> $content */
    public function assertValid(array $content): void
    {
        $this->validate($content)->assertValid();
    }

    /** @param array<string, mixed> $node @param array<string, true> $keys @param array<int, string> $errors */
    private function nodeKey(array $node, string $path, array &$keys, array &$errors): string
    {
        $key = trim((string) ($node['key'] ?? ''));
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            $errors[] = "{$path} key 必须是稳定的小写字母、数字或连字符标识。";

            return '';
        }
        if (isset($keys[$key])) {
            $errors[] = "Outline 节点 key {$key} 重复。";
        }
        $keys[$key] = true;

        return $key;
    }

    /** @param array<int, mixed> $nodes @param array<int, string> $errors */
    private function continuousSequences(array $nodes, string $path, array &$errors): void
    {
        if ($nodes === []) {
            return;
        }

        $actual = array_map(fn (mixed $node): mixed => is_array($node) ? ($node['sequence'] ?? null) : null, array_values($nodes));
        if ($actual !== range(1, count($nodes))) {
            $errors[] = "{$path} sequence 必须从 1 连续排列。";
        }
    }

    /** @param array<string, mixed> $node @param array<int, string> $errors */
    private function requiredText(array $node, string $field, string $path, array &$errors): void
    {
        if (trim((string) ($node[$field] ?? '')) === '') {
            $errors[] = "{$path} 缺少 {$field}。";
        }
    }

    /** @param array<int, string> $errors @return array<int, string> */
    private function stringList(mixed $value, string $path, array &$errors): array
    {
        if (! is_array($value)) {
            $errors[] = "{$path} 必须是数组。";

            return [];
        }
        $valid = [];
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                $errors[] = "{$path} 不能包含空项。";

                continue;
            }
            $valid[] = trim($item);
        }

        return $valid;
    }

    /** @param array<int, string> $errors */
    private function budget(mixed $budget, string $path, array &$errors): void
    {
        $min = is_array($budget) ? ($budget['min'] ?? null) : null;
        $max = is_array($budget) ? ($budget['max'] ?? null) : null;
        if (! is_int($min) || $min < 1 || ($max !== null && (! is_int($max) || $max < $min))) {
            $errors[] = "{$path} chapter_budget 必须满足 min >= 1 且 max 为空或不小于 min。";
        }
    }

    /** @param array<string, true> $candidateKeys @param array<int, string> $errors */
    private function candidates(mixed $candidates, string $type, string $path, array &$candidateKeys, array &$errors): void
    {
        if (! is_array($candidates)) {
            $errors[] = "{$path} {$type} candidates 必须是数组。";

            return;
        }
        $required = $type === 'Character'
            ? ['candidate_key', 'name', 'role', 'motivation', 'deduplication_basis', 'introduction_reason']
            : ['candidate_key', 'type', 'name', 'description', 'deduplication_basis', 'introduction_reason'];
        foreach (array_values($candidates) as $index => $candidate) {
            $candidatePath = "{$path} {$type} Candidate ".($index + 1);
            if (! is_array($candidate)) {
                $errors[] = "{$candidatePath} 结构无效。";

                continue;
            }
            foreach ($required as $field) {
                $this->requiredText($candidate, $field, $candidatePath, $errors);
            }
            $key = trim((string) ($candidate['candidate_key'] ?? ''));
            if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
                $errors[] = "{$candidatePath} candidate_key 无效。";
            } elseif (isset($candidateKeys[$key])) {
                $errors[] = "Outline Candidate key {$key} 重复。";
            }
            $candidateKeys[$key] = true;
            if ($type === 'Character') {
                foreach (['profile', 'personality', 'abilities', 'knowledge', 'possible_duplicate_character_ids'] as $field) {
                    if (! is_array($candidate[$field] ?? null)) {
                        $errors[] = "{$candidatePath} {$field} 必须是数组。";
                    }
                }
            } else {
                if (WorldEntityType::tryFrom((string) ($candidate['type'] ?? '')) === null) {
                    $errors[] = "{$candidatePath} type 无效。";
                }
                if (! is_array($candidate['possible_duplicate_entity_ids'] ?? null)) {
                    $errors[] = "{$candidatePath} possible_duplicate_entity_ids 必须是数组。";
                }
            }
            if (! is_int($candidate['target_scene_sequence'] ?? null) || $candidate['target_scene_sequence'] < 1) {
                $errors[] = "{$candidatePath} target_scene_sequence 必须是正整数。";
            }
        }
    }

    /** @param array<string, true> $beatKeys @param array<int, string> $errors */
    private function baseline(mixed $items, array $beatKeys, array &$errors): void
    {
        if (! is_array($items)) {
            $errors[] = 'baseline_completions 必须是数组。';

            return;
        }
        foreach (array_values($items) as $index => $item) {
            $path = 'Baseline Completion '.($index + 1);
            if (! is_array($item) || ! isset($beatKeys[(string) ($item['beat_key'] ?? '')])) {
                $errors[] = "{$path} 必须引用当前 Outline 的 Beat。";

                continue;
            }
            foreach (['reason', 'confirmed_by', 'confirmed_at'] as $field) {
                $this->requiredText($item, $field, $path, $errors);
            }
            $evidence = $item['evidence'] ?? null;
            if ((! is_string($evidence) || trim($evidence) === '') && (! is_array($evidence) || $evidence === [])) {
                $errors[] = "{$path} 缺少 evidence。";
            }
            if (! is_array($item['chapter_ids'] ?? null) || $item['chapter_ids'] === [] || collect($item['chapter_ids'])->contains(fn ($id): bool => ! is_int($id) || $id < 1)) {
                $errors[] = "{$path} chapter_ids 必须包含正整数。";
            }
        }
    }
}
