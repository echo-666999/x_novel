<?php

namespace App\Services;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;

final class ChapterRewriteLengthRepairer
{
    public const PROMPT_VERSION = 'rewrite-length-patch-v1';

    public function __construct(
        private readonly AiProvider $provider,
        private readonly DraftLengthPolicy $lengthPolicy,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $brief
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function repair(array $payload, array $brief, string $model, array $metadata): array
    {
        $requirement = $brief['length_requirement'];
        $lastRejection = null;

        for ($attempt = 1; $attempt <= (int) config('generation.max_rewrite_length_repair_attempts', 2); $attempt++) {
            $content = $payload['content'];
            $actual = $this->lengthPolicy->count($content);
            $minimum = (int) $requirement['minimum_words'];
            $maximum = (int) $requirement['maximum_words'];

            if ($actual >= $minimum && $actual <= $maximum) {
                break;
            }

            $tooLong = $actual > $maximum;
            [$preferredMinimum, $preferredMaximum] = $this->preferredRange($requirement);
            $response = $this->provider->generate(new AiRequest(
                model: $model,
                systemPrompt: $this->systemPrompt($tooLong),
                prompt: '请为以下重写稿生成局部字符补丁：'.json_encode([
                    'mode' => $tooLong ? 'compress' : 'expand',
                    'length_requirement' => [
                        ...$requirement,
                        'preferred_minimum_words' => $preferredMinimum,
                        'preferred_maximum_words' => $preferredMaximum,
                    ],
                    'current_words' => $actual,
                    'required_net_change' => $this->requiredNetChange(
                        $actual,
                        $preferredMinimum,
                        $preferredMaximum,
                        $tooLong,
                    ),
                    'repair_attempt' => $attempt,
                    'last_rejection' => $lastRejection,
                    'findings' => $brief['findings'],
                    'expected_fixes' => $brief['expected_fixes'],
                    'must_preserve' => $brief['must_preserve'],
                    'must_not_change' => $brief['must_not_change'],
                    'plan_acceptance' => $brief['plan_acceptance'],
                    'l4' => $brief['l4'],
                    'draft' => $content,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.1,
                maxTokens: (int) config('generation.rewrite_length_patch_max_output_tokens', 4_000),
                responseSchema: $this->schema(),
                promptVersion: self::PROMPT_VERSION,
                metadata: [
                    ...$metadata,
                    'length_repair_attempt' => $attempt,
                    'length_repair_mode' => $tooLong ? 'compress_patch' : 'expand_patch',
                ],
            ));

            try {
                $patch = StructuredOutput::require($response, 'rewrite_length_patch', 'Rewrite Length Patch');
                [$candidate, $lastRejection] = $this->apply($content, $patch, $tooLong);
            } catch (AiProviderException $exception) {
                if ($exception->errorCode !== 'rewrite_length_patch_schema_invalid') {
                    throw $exception;
                }

                $lastRejection = $exception->getMessage();

                continue;
            }

            if ($candidate === null) {
                continue;
            }

            $candidateWords = $this->lengthPolicy->count($candidate);
            if (! $this->movesSafelyTowardRange($actual, $candidateWords, $minimum, $maximum, $tooLong)) {
                $lastRejection = $tooLong
                    ? "补丁将 {$actual} 字改为 {$candidateWords} 字；压缩结果必须减少字数且不得低于 {$minimum} 字。"
                    : "补丁将 {$actual} 字改为 {$candidateWords} 字；扩写结果必须增加字数且不得超过 {$maximum} 字。";

                continue;
            }

            $payload['content'] = $candidate;
        }

        return $payload;
    }

    private function systemPrompt(bool $tooLong): string
    {
        $direction = $tooLong
            ? '当前稿过长。每项 replacement 必须短于 search，可以删除重复说明，但不得删除剧情结果、事实、必要过渡或章节结尾。'
            : '当前稿过短。每项 replacement 必须长于 search，只能在原有场景内补足动作、对话、感官、心理或过渡，不得新增重大事实。';

        return '你是 XNovel 整章重写稿的局部字符补丁器。'.$direction.'不得返回完整重写稿。只返回 edits；每项 search 必须逐字复制 draft 中一段连续且只出现一次的文本，replacement 是其完整替换文本。不得使用省略号代替原文，不得拼接不连续片段。保留 l4 的 POV、时态和文风，优先让最终字数进入 preferred_minimum_words 到 preferred_maximum_words；hard minimum 和 maximum 绝不能跨越。';
    }

    /** @param array<string, mixed> $requirement
     * @return array{int, int}
     */
    private function preferredRange(array $requirement): array
    {
        if (isset($requirement['preferred_minimum_words'], $requirement['preferred_maximum_words'])) {
            return [
                (int) $requirement['preferred_minimum_words'],
                (int) $requirement['preferred_maximum_words'],
            ];
        }

        $target = (int) $requirement['target_words'];
        $minimum = (int) $requirement['minimum_words'];
        $maximum = (int) $requirement['maximum_words'];
        $preferredMinimum = max($minimum, (int) ceil($target * .95));
        $preferredMaximum = min($maximum, (int) floor($target * 1.05));

        return $preferredMinimum <= $preferredMaximum
            ? [$preferredMinimum, $preferredMaximum]
            : [$minimum, $maximum];
    }

    /** @return array{unit: string, minimum: int, maximum: int} */
    private function requiredNetChange(int $actual, int $preferredMinimum, int $preferredMaximum, bool $tooLong): array
    {
        return [
            'unit' => '排除所有空白和换行后的多字节字符数',
            'minimum' => $tooLong
                ? max(1, $actual - $preferredMaximum)
                : max(1, $preferredMinimum - $actual),
            'maximum' => $tooLong
                ? max(1, $actual - $preferredMinimum)
                : max(1, $preferredMaximum - $actual),
        ];
    }

    /** @return array<string, mixed> */
    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['edits'],
            'properties' => [
                'edits' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['search', 'replacement'],
                        'properties' => [
                            'search' => ['type' => 'string'],
                            'replacement' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{string|null, string|null}
     */
    private function apply(string $content, array $patch, bool $tooLong): array
    {
        if (! $this->hasExactKeys($patch, ['edits']) || ! is_array($patch['edits']) || ! array_is_list($patch['edits'])) {
            return [null, '补丁必须只包含 edits 列表。'];
        }
        if ($patch['edits'] === [] || count($patch['edits']) > 12) {
            return [null, 'edits 必须包含 1～12 项局部替换。'];
        }

        $candidate = $content;
        foreach ($patch['edits'] as $index => $edit) {
            if (! is_array($edit) || ! $this->hasExactKeys($edit, ['search', 'replacement'])) {
                return [null, "edits.{$index} 必须只包含 search 和 replacement。"];
            }
            $search = $edit['search'];
            $replacement = $edit['replacement'];
            if (! is_string($search) || $search === '' || ! is_string($replacement)) {
                return [null, "edits.{$index} 的 search 必须为非空字符串，replacement 必须为字符串。"];
            }
            if (substr_count($candidate, $search) !== 1) {
                return [null, "edits.{$index}.search 必须在当前正文中逐字且唯一命中。"];
            }

            $searchWords = $this->lengthPolicy->count($search);
            $replacementWords = $this->lengthPolicy->count($replacement);
            if (($tooLong && $replacementWords >= $searchWords) || (! $tooLong && $replacementWords <= $searchWords)) {
                return [null, $tooLong
                    ? "edits.{$index}.replacement 必须短于 search。"
                    : "edits.{$index}.replacement 必须长于 search。"];
            }

            $candidate = str_replace($search, $replacement, $candidate);
        }

        return [$candidate, null];
    }

    private function movesSafelyTowardRange(int $actual, int $candidate, int $minimum, int $maximum, bool $tooLong): bool
    {
        return $tooLong
            ? $candidate < $actual && $candidate >= $minimum
            : $candidate > $actual && $candidate <= $maximum;
    }

    /** @param array<int, string> $keys */
    private function hasExactKeys(array $value, array $keys): bool
    {
        $actual = array_keys($value);
        sort($actual);
        sort($keys);

        return $actual === $keys;
    }
}
