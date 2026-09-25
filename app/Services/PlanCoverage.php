<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class PlanCoverage
{
    public const ELEMENTS = ['goal', 'conflict', 'turn', 'outcome'];

    public const STATUSES = ['fulfilled', 'missing', 'contradicted'];

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        $item = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'evidence'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
                'evidence' => ['type' => ['string', 'null']],
            ],
        ];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::ELEMENTS,
            'properties' => array_fill_keys(self::ELEMENTS, $item),
        ];
    }

    /** @return array<string, array{status: string, evidence: string|null}> */
    public static function validate(mixed $coverage, string $content, string $path = 'coverage'): array
    {
        if (! is_array($coverage) || ! self::hasExactKeys($coverage, self::ELEMENTS)) {
            throw ValidationException::withMessages([$path => 'Coverage 必须完整包含 goal、conflict、turn、outcome。']);
        }

        foreach (self::ELEMENTS as $element) {
            $item = $coverage[$element];
            $itemPath = "{$path}.{$element}";

            if (! is_array($item) || ! self::hasExactKeys($item, ['status', 'evidence'])) {
                throw ValidationException::withMessages([$itemPath => 'Coverage Item 必须只包含 status 和 evidence。']);
            }

            $status = $item['status'];
            $evidence = $item['evidence'];

            if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
                throw ValidationException::withMessages(["{$itemPath}.status" => 'Coverage status 必须是 fulfilled、missing 或 contradicted。']);
            }

            if ($status === 'missing') {
                if ($evidence !== null) {
                    throw ValidationException::withMessages(["{$itemPath}.evidence" => 'missing Coverage 的 evidence 必须为 null。']);
                }

                continue;
            }

            if (! is_string($evidence) || trim($evidence) === '') {
                throw ValidationException::withMessages(["{$itemPath}.evidence" => "{$itemPath}.evidence：fulfilled 或 contradicted Coverage 的 evidence 必须逐字来自当前正文。"]);
            }

            $evidence = self::resolveExactEvidence($content, $evidence);

            if (! str_contains($content, $evidence)) {
                throw ValidationException::withMessages(["{$itemPath}.evidence" => "{$itemPath}.evidence：fulfilled 或 contradicted Coverage 的 evidence 必须逐字来自当前正文。"]);
            }

            $coverage[$element]['evidence'] = $evidence;
        }

        return $coverage;
    }

    /**
     * Keep statuses that have verifiable evidence and conservatively mark unverifiable claims as missing.
     *
     * @param  array<string, mixed>  $coverage
     * @return array<string, array{status: string, evidence: string|null}>
     */
    public static function fallbackUnverifiableEvidenceToMissing(array $coverage, string $content, string $path): array
    {
        if (! self::hasExactKeys($coverage, self::ELEMENTS)) {
            return self::validate($coverage, $content, $path);
        }

        foreach (self::ELEMENTS as $element) {
            $item = $coverage[$element] ?? null;

            if (! is_array($item)
                || ! self::hasExactKeys($item, ['status', 'evidence'])
                || ! in_array($item['status'] ?? null, self::STATUSES, true)) {
                return self::validate($coverage, $content, $path);
            }

            if ($item['status'] === 'missing') {
                $coverage[$element]['evidence'] = null;

                continue;
            }

            $evidence = is_string($item['evidence'] ?? null)
                ? self::resolveExactEvidence($content, $item['evidence'])
                : '';

            if ($evidence !== '' && str_contains($content, $evidence)) {
                $coverage[$element]['evidence'] = $evidence;

                continue;
            }

            $coverage[$element] = ['status' => 'missing', 'evidence' => null];
        }

        return self::validate($coverage, $content, $path);
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, mixed>  $scenePlan
     * @return array<string, mixed>
     */
    public static function expectations(array $scene, array $scenePlan = []): array
    {
        return [
            'goal' => $scene['goal'] ?? null,
            'conflict' => $scene['conflict'] ?? null,
            'turn' => $scene['turn'] ?? null,
            'outcome' => [
                'description' => $scene['outcome'] ?? null,
                'allowed' => data_get($scenePlan, 'outcome_allowed', []),
                'forbidden' => data_get($scenePlan, 'outcome_forbidden', []),
            ],
        ];
    }

    /**
     * @param  array<string, array{status: string, evidence: string|null}>  $coverage
     * @param  array<string, mixed>  $expectations
     * @return array<int, array<string, mixed>>
     */
    public static function findings(int $sceneId, array $coverage, array $expectations, string $phase): array
    {
        $labels = [
            'goal' => '目标',
            'conflict' => '冲突',
            'turn' => '转折',
            'outcome' => '结果',
        ];

        return collect(self::ELEMENTS)
            ->filter(fn (string $element): bool => $coverage[$element]['status'] !== 'fulfilled')
            ->map(function (string $element) use ($sceneId, $coverage, $expectations, $phase, $labels): array {
                $status = $coverage[$element]['status'];

                return [
                    'code' => $status === 'missing'
                        ? 'SCENE_PLAN_COVERAGE_MISSING'
                        : 'SCENE_PLAN_COVERAGE_CONTRADICTED',
                    'dimension' => 'plan',
                    'severity' => 'error',
                    'scene_id' => $sceneId,
                    'scope' => 'scene',
                    'auto_fixable' => true,
                    'requires_human_decision' => false,
                    'plan_element' => $element,
                    'coverage_status' => $status,
                    'expected' => $expectations[$element] ?? null,
                    'evidence' => $coverage[$element]['evidence'],
                    'message' => '场景计划的'.$labels[$element].($status === 'missing' ? '未在正文中落实。' : '被正文反转。'),
                    'source' => $phase,
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<int, string> $keys */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }

    public static function resolveExactEvidence(string $content, string $evidence): string
    {
        $evidence = trim($evidence);

        if ($evidence === '' || str_contains($content, $evidence)) {
            return $evidence;
        }

        foreach ([['“', '”'], ['‘', '’'], ['「', '」'], ['『', '』'], ['"', '"'], ["'", "'"]] as [$open, $close]) {
            if (str_starts_with($evidence, $open) && str_ends_with($evidence, $close)) {
                $unwrapped = mb_substr($evidence, mb_strlen($open), mb_strlen($evidence) - mb_strlen($open) - mb_strlen($close));

                if ($unwrapped !== '' && str_contains($content, $unwrapped)) {
                    return $unwrapped;
                }
            }
        }

        return self::resolveWhitespaceEquivalentEvidence($content, $evidence)
            ?? self::resolveHighConfidenceEvidence($content, $evidence)
            ?? $evidence;
    }

    /**
     * Reduce a model-composed list of quotations to one verifiable contiguous excerpt.
     *
     * Provider responses occasionally join several valid excerpts with punctuation even
     * though the persisted evidence contract accepts one string. Keep the strongest
     * verifiable excerpt so a formatting mistake cannot discard an otherwise usable
     * Review, while the original provider response remains available in request logs.
     */
    public static function resolveRepresentativeExactEvidence(string $content, string $evidence): string
    {
        $evidence = trim($evidence);
        $resolved = self::resolveExactEvidence($content, $evidence);

        if ($resolved === '' || str_contains($content, $resolved)) {
            return $resolved;
        }

        $candidates = preg_split('/(?:……|…{1,}|\.{3,}|[；;])/u', $evidence) ?: [];
        preg_match_all("/(?:“([^”]+)”|‘([^’]+)’|「([^」]+)」|『([^』]+)』|\"([^\"]+)\"|'([^']+)')/u", $evidence, $quoted, PREG_SET_ORDER);
        foreach ($quoted as $match) {
            foreach (array_slice($match, 1) as $quote) {
                if ($quote !== '') {
                    $candidates[] = $quote;
                    break;
                }
            }
        }

        $best = null;
        foreach (array_unique($candidates) as $candidate) {
            $candidate = trim($candidate, " \t\n\r\0\x0B\"'“”‘’「」『』");
            if ($candidate === '') {
                continue;
            }

            $candidate = self::resolveExactEvidence($content, $candidate);
            if (! str_contains($content, $candidate)) {
                continue;
            }

            if ($best === null || mb_strlen($candidate) > mb_strlen($best)) {
                $best = $candidate;
            }
        }

        return $best ?? $evidence;
    }

    private static function resolveWhitespaceEquivalentEvidence(string $content, string $evidence): ?string
    {
        $contentCharacters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $evidenceWithoutWhitespace = preg_replace('/\s+/u', '', $evidence);

        if (! is_array($contentCharacters) || ! is_string($evidenceWithoutWhitespace) || $evidenceWithoutWhitespace === '') {
            return null;
        }

        $contentWithoutWhitespace = '';
        $contentOffsets = [];

        foreach ($contentCharacters as $offset => $character) {
            if (preg_match('/\s/u', $character) === 1) {
                continue;
            }

            $contentWithoutWhitespace .= $character;
            $contentOffsets[] = $offset;
        }

        $normalizedPosition = mb_strpos($contentWithoutWhitespace, $evidenceWithoutWhitespace);

        if ($normalizedPosition === false) {
            return null;
        }

        $normalizedEnd = $normalizedPosition + mb_strlen($evidenceWithoutWhitespace) - 1;
        $start = $contentOffsets[$normalizedPosition] ?? null;
        $end = $contentOffsets[$normalizedEnd] ?? null;

        if ($start === null || $end === null) {
            return null;
        }

        return implode('', array_slice($contentCharacters, $start, $end - $start + 1));
    }

    private static function resolveHighConfidenceEvidence(string $content, string $evidence): ?string
    {
        $contentCharacters = preg_split('//u', $content, -1, PREG_SPLIT_NO_EMPTY);
        $evidenceCharacters = preg_split('//u', $evidence, -1, PREG_SPLIT_NO_EMPTY);

        if (! is_array($contentCharacters) || ! is_array($evidenceCharacters) || $evidenceCharacters === []) {
            return null;
        }

        $previous = array_fill(0, count($evidenceCharacters) + 1, 0);
        $bestLength = 0;
        $bestEnd = 0;

        foreach ($contentCharacters as $contentIndex => $contentCharacter) {
            $current = array_fill(0, count($evidenceCharacters) + 1, 0);

            foreach ($evidenceCharacters as $evidenceIndex => $evidenceCharacter) {
                if ($contentCharacter !== $evidenceCharacter) {
                    continue;
                }

                $current[$evidenceIndex + 1] = $previous[$evidenceIndex] + 1;

                if ($current[$evidenceIndex + 1] > $bestLength) {
                    $bestLength = $current[$evidenceIndex + 1];
                    $bestEnd = $contentIndex + 1;
                }
            }

            $previous = $current;
        }

        if ($bestLength < 8 || $bestLength / count($evidenceCharacters) < .8) {
            return null;
        }

        return implode('', array_slice($contentCharacters, $bestEnd - $bestLength, $bestLength));
    }
}
