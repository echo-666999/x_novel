<?php

namespace App\Services;

use App\Data\NormalizedNovelOutline;
use App\Data\NovelOutlineValidationResult;
use App\Enums\StoryArcType;
use App\Enums\WorldEntityType;
use Illuminate\Validation\ValidationException;

/**
 * 对完整关系化大纲执行跨层级、顺序、主线和 Handoff 业务校验。
 */
class NormalizedNovelOutlineValidator
{
    /**
     * 收集完整 DTO 中的所有确定性校验错误。
     */
    public function validate(NormalizedNovelOutline|array $outline): NovelOutlineValidationResult
    {
        $data = $outline instanceof NormalizedNovelOutline ? $outline->toArray() : $outline;
        $errors = [];
        $this->requiredText($data, 'title', 'Outline', $errors);
        $this->requiredText($data, 'summary', 'Outline', $errors);
        $this->stringList($data['must_include'] ?? null, 'Outline must_include', $errors);
        $this->stringList($data['must_not_include'] ?? null, 'Outline must_not_include', $errors);

        $volumes = $data['volumes'] ?? null;
        if (! is_array($volumes) || $volumes === []) {
            $errors[] = 'Outline 至少需要一个 Volume。';

            return new NovelOutlineValidationResult(array_values(array_unique($errors)));
        }

        $this->continuousSequences($volumes, 'Volume', $errors);
        $volumeKeys = [];
        $arcKeys = [];
        $beatKeys = [];
        $milestoneKeys = [];
        $candidateKeys = [];
        $mainArcs = [];
        $mainBeats = [];

        foreach (array_values($volumes) as $volumeIndex => $volume) {
            $volumePath = 'Volume '.($volumeIndex + 1);
            if (! is_array($volume)) {
                $errors[] = "{$volumePath} 结构无效。";

                continue;
            }
            $this->stableKey($volume, 'key', $volumePath, $volumeKeys, $errors);
            foreach (['title', 'goal', 'climax'] as $field) {
                $this->requiredText($volume, $field, $volumePath, $errors);
            }
            if (! is_int($volume['target_words'] ?? null) || $volume['target_words'] < 1) {
                $errors[] = "{$volumePath} target_words 必须是正整数。";
            }

            $arcs = $volume['arcs'] ?? null;
            if (! is_array($arcs) || $arcs === []) {
                $errors[] = "{$volumePath} 至少需要一个 Arc。";

                continue;
            }
            $this->continuousSequences($arcs, "{$volumePath} Arc", $errors);
            foreach (array_values($arcs) as $arcIndex => $arc) {
                $arcPath = "{$volumePath} Arc ".($arcIndex + 1);
                if (! is_array($arc)) {
                    $errors[] = "{$arcPath} 结构无效。";

                    continue;
                }
                $this->stableKey($arc, 'key', $arcPath, $arcKeys, $errors);
                foreach (['title', 'goal', 'stakes'] as $field) {
                    $this->requiredText($arc, $field, $arcPath, $errors);
                }
                $this->stringList($arc['completion_conditions'] ?? null, "{$arcPath} completion_conditions", $errors, true);
                $type = ($arc['type'] ?? null) instanceof StoryArcType ? $arc['type']->value : ($arc['type'] ?? null);
                if (StoryArcType::tryFrom((string) $type) === null) {
                    $errors[] = "{$arcPath} type 无效。";

                    continue;
                }
                if ($type === StoryArcType::Main->value) {
                    if (! is_int($arc['mainline_sequence'] ?? null) || $arc['mainline_sequence'] < 1) {
                        $errors[] = "{$arcPath} Main Arc 必须有正整数 mainline_sequence。";
                    } else {
                        $mainArcs[] = $arc;
                    }
                } elseif (($arc['mainline_sequence'] ?? null) !== null) {
                    $errors[] = "{$arcPath} Subplot 的 mainline_sequence 必须为空。";
                }

                $beats = $arc['beats'] ?? null;
                if (! is_array($beats) || $beats === []) {
                    $errors[] = "{$arcPath} 至少需要一个 Beat。";

                    continue;
                }
                $this->continuousSequences($beats, "{$arcPath} Beat", $errors);
                foreach (array_values($beats) as $beatIndex => $beat) {
                    $beatPath = "{$arcPath} Beat ".($beatIndex + 1);
                    if (! is_array($beat)) {
                        $errors[] = "{$beatPath} 结构无效。";

                        continue;
                    }
                    $beatKey = $this->stableKey($beat, 'key', $beatPath, $beatKeys, $errors);
                    foreach (['title', 'summary'] as $field) {
                        $this->requiredText($beat, $field, $beatPath, $errors);
                    }
                    $this->budget($beat['chapter_budget'] ?? null, $beatPath, $errors);
                    $this->stringList($beat['acceptance_criteria'] ?? null, "{$beatPath} acceptance_criteria", $errors, true);
                    $this->stringList($beat['must_include'] ?? null, "{$beatPath} must_include", $errors);
                    $this->stringList($beat['must_not_include'] ?? null, "{$beatPath} must_not_include", $errors);
                    $this->candidates($beat['character_candidates'] ?? null, true, $beatPath, $candidateKeys, $errors);
                    $this->candidates($beat['world_entity_candidates'] ?? null, false, $beatPath, $candidateKeys, $errors);

                    $milestones = $beat['milestones'] ?? null;
                    if ($type === StoryArcType::Main->value) {
                        if (! is_int($beat['mainline_sequence'] ?? null) || $beat['mainline_sequence'] < 1) {
                            $errors[] = "{$beatPath} Main Beat 必须有正整数 mainline_sequence。";
                        }
                        if (! is_array($milestones) || $milestones === []) {
                            $errors[] = "{$beatPath} Main Beat 至少需要一个 Milestone。";
                            $milestones = [];
                        }
                        $mainBeats[] = ['key' => $beatKey, 'sequence' => $beat['mainline_sequence'] ?? null, 'handoff' => $beat['handoff'] ?? null];
                    } else {
                        if (($beat['mainline_sequence'] ?? null) !== null) {
                            $errors[] = "{$beatPath} Subplot Beat 的 mainline_sequence 必须为空。";
                        }
                        if (is_array($milestones) && $milestones !== []) {
                            $errors[] = "{$beatPath} Subplot Beat 首版不能创建 Milestone。";
                        }
                        if (is_array($beat['handoff'] ?? null) && filled($beat['handoff']['next_beat_key'] ?? null)) {
                            $errors[] = "{$beatPath} Subplot Beat 首版不能创建 Mainline Handoff。";
                        }
                    }

                    if (is_array($milestones)) {
                        $this->continuousSequences($milestones, "{$beatPath} Milestone", $errors);
                        foreach (array_values($milestones) as $milestoneIndex => $milestone) {
                            $milestonePath = "{$beatPath} Milestone ".($milestoneIndex + 1);
                            if (! is_array($milestone)) {
                                $errors[] = "{$milestonePath} 结构无效。";

                                continue;
                            }
                            $this->stableKey($milestone, 'key', $milestonePath, $milestoneKeys, $errors);
                            foreach (['title', 'objective'] as $field) {
                                $this->requiredText($milestone, $field, $milestonePath, $errors);
                            }
                            $this->stringList($milestone['acceptance_criteria'] ?? null, "{$milestonePath} acceptance_criteria", $errors, true);
                            $this->stringList($milestone['must_include'] ?? null, "{$milestonePath} must_include", $errors);
                            $this->stringList($milestone['must_not_include'] ?? null, "{$milestonePath} must_not_include", $errors);
                        }
                    }
                }
            }
        }

        $this->continuousMainline($mainArcs, 'Main Arc', $errors);
        usort($mainBeats, fn (array $left, array $right): int => (int) $left['sequence'] <=> (int) $right['sequence']);
        $this->continuousMainline($mainBeats, 'Main Beat', $errors);
        $this->handoffs($mainBeats, $errors);

        return new NovelOutlineValidationResult(array_values(array_unique($errors)));
    }

    /**
     * 在持久化前拒绝任一无效节点，确保事务不会保存部分版本。
     */
    public function assertValid(NormalizedNovelOutline|array $outline): void
    {
        $result = $this->validate($outline);
        if (! $result->isValid()) {
            throw ValidationException::withMessages(['outline' => $result->errors]);
        }
    }

    /** @param array<string, mixed> $node @param array<string, true> $keys @param array<int, string> $errors */
    private function stableKey(array $node, string $field, string $path, array &$keys, array &$errors): string
    {
        $key = trim((string) ($node[$field] ?? ''));
        if (! preg_match('/^[a-z0-9][a-z0-9-]*$/', $key)) {
            $errors[] = "{$path} {$field} 必须是稳定的小写字母、数字或连字符标识。";

            return '';
        }
        if (isset($keys[$key])) {
            $errors[] = "{$path} {$field}={$key} 在当前 Outline 内重复。";
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

    /** @param array<int, array<string, mixed>> $nodes @param array<int, string> $errors */
    private function continuousMainline(array $nodes, string $path, array &$errors): void
    {
        if ($nodes === []) {
            return;
        }
        $actual = array_map(fn (array $node): mixed => $node['mainline_sequence'] ?? $node['sequence'] ?? null, $nodes);
        sort($actual);
        if ($actual !== range(1, count($nodes))) {
            $errors[] = "{$path} mainline_sequence 必须从 1 连续排列。";
        }
    }

    /** @param array<string, mixed> $node @param array<int, string> $errors */
    private function requiredText(array $node, string $field, string $path, array &$errors): void
    {
        if (trim((string) ($node[$field] ?? '')) === '') {
            $errors[] = "{$path} 缺少 {$field}。";
        }
    }

    /** @param array<int, string> $errors */
    private function stringList(mixed $value, string $path, array &$errors, bool $required = false): void
    {
        if (! is_array($value) || ($required && $value === [])) {
            $errors[] = "{$path} 必须是".($required ? '非空' : '').'字符串数组。';

            return;
        }
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                $errors[] = "{$path} 不能包含空项。";

                return;
            }
        }
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
    private function candidates(mixed $items, bool $character, string $path, array &$candidateKeys, array &$errors): void
    {
        if (! is_array($items)) {
            $errors[] = "{$path} candidates 必须是数组。";

            return;
        }
        foreach (array_values($items) as $index => $item) {
            $candidatePath = $path.' Candidate '.($index + 1);
            if (! is_array($item)) {
                $errors[] = "{$candidatePath} 结构无效。";

                continue;
            }
            $required = $character
                ? ['candidate_key', 'name', 'role', 'motivation', 'deduplication_basis', 'introduction_reason']
                : ['candidate_key', 'type', 'name', 'description', 'deduplication_basis', 'introduction_reason'];
            foreach ($required as $field) {
                $this->requiredText($item, $field, $candidatePath, $errors);
            }
            $this->stableKey($item, 'candidate_key', $candidatePath, $candidateKeys, $errors);
            if (! $character && WorldEntityType::tryFrom((string) ($item['type'] ?? '')) === null) {
                $errors[] = "{$candidatePath} type 无效。";
            }
            if (! is_int($item['target_scene_sequence'] ?? null) || $item['target_scene_sequence'] < 1) {
                $errors[] = "{$candidatePath} target_scene_sequence 必须是正整数。";
            }
        }
    }

    /** @param array<int, array<string, mixed>> $mainBeats @param array<int, string> $errors */
    private function handoffs(array $mainBeats, array &$errors): void
    {
        foreach ($mainBeats as $index => $beat) {
            $handoff = $beat['handoff'];
            $expected = $mainBeats[$index + 1]['key'] ?? null;
            $actual = is_array($handoff) ? ($handoff['next_beat_key'] ?? null) : null;
            if ($actual !== $expected) {
                $errors[] = $expected === null
                    ? "最后一个 Main Beat {$beat['key']} 的 handoff_next_beat 必须为空。"
                    : "Main Beat {$beat['key']} 的 Handoff 必须指向相邻 Beat {$expected}。";
            }
            if ($expected === null) {
                continue;
            }
            if (! is_array($handoff)) {
                $errors[] = "Main Beat {$beat['key']} 缺少 Handoff。";

                continue;
            }
            foreach (['transition_mode', 'exit_result', 'next_trigger'] as $field) {
                $this->requiredText($handoff, $field, "Main Beat {$beat['key']} Handoff", $errors);
            }
            foreach (['carried_states', 'open_threads', 'required_transition', 'forbidden_jump'] as $field) {
                $this->stringList($handoff[$field] ?? null, "Main Beat {$beat['key']} Handoff {$field}", $errors);
            }
        }
    }
}
