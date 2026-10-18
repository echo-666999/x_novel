<?php

namespace App\Services;

use Illuminate\Support\Facades\Validator;

/** 原文不可变，来源与默认值由服务端计算；模型不能修改用户明确输入。 */
final class NovelIntentContract
{
    public const MODES = ['full_book' => '自动完成整本', 'opening' => '先生成开篇', 'manual_review' => '逐章审核'];

    /** 校验输入并保存来源，默认策略不伪装为用户明确要求。 */
    public function make(string $premise, array $options): array
    {
        $options = array_filter($options, fn ($value) => $value !== null && $value !== '');
        Validator::make(['premise' => $premise, ...$options], [
            'premise' => ['required', 'string', 'max:10000'],
            'target_words' => ['sometimes', 'integer', 'min:1', 'max:10000000'],
            'generation_chapter_target_words' => ['sometimes', 'integer', 'between:500,20000'],
            'mode' => ['sometimes', 'in:full_book,opening,manual_review'],
            'opening_chapter_count' => ['sometimes', 'integer', 'between:1,100'],
            'title' => ['sometimes', 'string', 'max:255'], 'genre' => ['sometimes', 'string', 'max:255'],
        ])->validate();
        // 只识别明确的数字篇幅表达；更复杂的自然语言交给设定解析，绝不臆测数字。
        preg_match_all('/(?:总(?:字数|篇幅)|全书|共|目标(?:字数)?|写(?:一部|一本)?)[：:\s约为]*(\d+(?:\.\d+)?)\s*(万|百万)?字/u', $premise, $matches, PREG_SET_ORDER);
        $targets = array_values(array_unique(array_map(fn ($m) => (int) round((float) $m[1] * match ($m[2] ?? '') {
            '万' => 10000, '百万' => 1000000, default => 1
        }), $matches)));
        $target = isset($options['target_words']) ? (int) $options['target_words'] : ($targets[0] ?? (int) config('generation.book.target_words', 100000));
        // 自然语言提取出的数值同样受边界约束，避免越界目标进入数据库后才失败。
        Validator::make(['target_words' => $target], ['target_words' => ['integer', 'between:1,10000000']])->validate();
        $requirements = [];
        foreach (preg_split('/(?<=[。！？；\n])/u', $premise, -1, PREG_SPLIT_NO_EMPTY) as $clause) {
            if (trim($clause) !== '') {
                $requirements[] = ['id' => 'r'.(count($requirements) + 1), 'quote' => $clause];
            }
        }

        return ['schema_version' => 2, 'premise' => $premise, 'requirements' => $requirements,
            'target_words' => $target, 'target_source' => isset($options['target_words']) ? 'user_form' : ($targets ? 'user_premise' : 'system_default'),
            'premise_targets' => $targets, 'target_conflict' => count($targets) > 1 || ($targets && isset($options['target_words']) && ! in_array($target, $targets, true)),
            'chapter_target_words' => (int) ($options['generation_chapter_target_words'] ?? config('generation.book.chapter_target_words', 3000)),
            'mode' => $options['mode'] ?? 'full_book', 'opening_chapter_count' => (int) ($options['opening_chapter_count'] ?? 3),
            'explicit' => array_intersect_key($options, array_flip(['title', 'genre', 'target_words', 'generation_chapter_target_words', 'mode', 'opening_chapter_count'])),
            'budget' => app(GenerationCostPolicy::class)->normalize((array) ($options['budget_limits'] ?? []))];
    }
}
