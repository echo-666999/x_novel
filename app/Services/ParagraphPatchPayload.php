<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

final class ParagraphPatchPayload
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['search', 'replacement', 'self_check'],
            'properties' => [
                'search' => ['type' => 'string', 'minLength' => 1],
                'replacement' => ['type' => 'string', 'minLength' => 1],
                'self_check' => PlanCoverage::schema(),
            ],
        ];
    }

    /** @param array<string, mixed> $payload @return array{content: string, self_check: array<string, mixed>, patch: array{search: string, replacement: string}} */
    public static function apply(array $payload, string $source): array
    {
        $keys = array_keys($payload);
        sort($keys);
        if ($keys !== ['replacement', 'search', 'self_check']) {
            throw ValidationException::withMessages(['paragraph_patch' => 'Paragraph Patch 必须只包含 search、replacement 和 self_check。']);
        }
        $search = is_string($payload['search']) ? $payload['search'] : '';
        $replacement = is_string($payload['replacement']) ? $payload['replacement'] : '';
        if ($search === '' || trim($replacement) === '' || substr_count($source, $search) !== 1) {
            throw ValidationException::withMessages(['paragraph_patch.search' => 'Paragraph Patch 的 search 必须在目标 Scene 当前正文中唯一命中一次。']);
        }
        $content = substr_replace($source, $replacement, strpos($source, $search), strlen($search));

        return [
            'content' => $content,
            'self_check' => PlanCoverage::validate($payload['self_check'], $content, 'self_check'),
            'patch' => ['search' => $search, 'replacement' => $replacement],
        ];
    }
}
