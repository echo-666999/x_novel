<?php

namespace App\Services;

use App\Data\NormalizedNovelOutline;
use App\Models\Novel;
use Illuminate\Validation\ValidationException;

/**
 * 冻结并核对全书字数、分卷字数、主线 Beat 章节预算与单章目标之间的换算关系。
 */
final class NovelOutlineLengthContract
{
    public const SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public function freeze(int $novelTargetWords, int $chapterTargetWords, int $volumeCount): array
    {
        if ($novelTargetWords < 1 || $chapterTargetWords < 1 || $volumeCount < 1) {
            throw ValidationException::withMessages([
                'length_contract' => '小说目标字数、单章目标字数和预计分卷数必须是正整数。',
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'novel_target_words' => $novelTargetWords,
            'chapter_target_words' => $chapterTargetWords,
            'target_chapter_count' => (int) ceil($novelTargetWords / $chapterTargetWords),
            'requested_volume_count' => $volumeCount,
        ];
    }

    /**
     * Structure 必须完整分配已冻结的全书目标字数，不能让各卷字数与小说总量分叉。
     *
     * @return array<string, mixed>
     */
    public function validateStructure(array $structure, array $frozen): array
    {
        $this->assertBase($frozen);
        $volumes = array_values($structure['volumes'] ?? []);
        if (count($volumes) !== (int) $frozen['requested_volume_count']) {
            throw ValidationException::withMessages([
                'length_contract' => 'Structure 的分卷数与冻结合同不一致。',
            ]);
        }

        $total = array_sum(array_map(
            static fn (mixed $volume): int => is_array($volume) ? (int) ($volume['target_words'] ?? 0) : 0,
            $volumes,
        ));
        if ($total !== (int) $frozen['novel_target_words']) {
            throw ValidationException::withMessages([
                'length_contract' => "各 Volume target_words 合计 {$total}，必须等于冻结的小说目标字数 {$frozen['novel_target_words']}。",
            ]);
        }

        return [
            ...$frozen,
            'volumes' => array_map(fn (array $volume): array => [
                'volume_key' => (string) $volume['key'],
                'target_words' => (int) $volume['target_words'],
                'target_chapter_count' => $this->targetChapterCount((int) $volume['target_words'], (int) $frozen['chapter_target_words']),
            ], $volumes),
        ];
    }

    /**
     * Skeleton 完成后才能跨 Arc 汇总主线 Beat 预算；支线可与主线共用章节，不重复计入卷章节数。
     *
     * @return array<string, mixed>
     */
    public function complete(array $outline, array $frozen): array
    {
        $contract = $this->validateStructure($outline, $frozen);
        foreach ($outline['volumes'] as $index => $volume) {
            $minimum = 0;
            $maximum = 0;
            $hasOpenMaximum = false;
            $mainBeatCount = 0;

            foreach ($volume['arcs'] ?? [] as $arc) {
                if (($arc['type'] ?? null) !== 'main') {
                    continue;
                }
                foreach ($arc['beats'] ?? [] as $beat) {
                    $mainBeatCount++;
                    $minimum += (int) data_get($beat, 'chapter_budget.min', 0);
                    $beatMaximum = data_get($beat, 'chapter_budget.max');
                    if ($beatMaximum === null) {
                        $hasOpenMaximum = true;
                    } else {
                        $maximum += (int) $beatMaximum;
                    }
                }
            }

            if ($mainBeatCount === 0) {
                throw ValidationException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 缺少可承载章节预算的 Main Beat。",
                ]);
            }

            $target = (int) $contract['volumes'][$index]['target_chapter_count'];
            $upper = $hasOpenMaximum ? null : $maximum;
            if ($minimum > $target || ($upper !== null && $upper < $target)) {
                $range = $upper === null ? "{$minimum} 章以上" : "{$minimum} 至 {$upper} 章";
                throw ValidationException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 按字数折算需约 {$target} 章，但 Main Beat 预算合计为 {$range}。",
                ]);
            }

            $contract['volumes'][$index]['mainline_beat_count'] = $mainBeatCount;
            $contract['volumes'][$index]['mainline_chapter_budget'] = [
                'min' => $minimum,
                'max' => $upper,
            ];
        }

        return $contract;
    }

    /**
     * AI / Revision Outline 读取不可变 Blueprint 合同；历史或人工计划只能明确标记为当前设置推导值。
     *
     * @return array<string, mixed>
     */
    public function forNovel(Novel $novel): array
    {
        $outline = $novel->currentOutline()->with('sourceArtifact')->first();
        if ($outline === null) {
            throw ValidationException::withMessages(['length_contract' => '缺少 Current Outline，无法解析字数合同。']);
        }

        $stored = data_get($outline->sourceArtifact?->data, 'length_contract');
        if (is_array($stored)) {
            $outlineData = NormalizedNovelOutline::fromModel($outline)->toArray();
            $recomputed = $this->complete($outlineData, $this->baseFrom($stored));
            if ($this->semanticHash($recomputed) !== $this->semanticHash($stored)) {
                throw ValidationException::withMessages([
                    'length_contract' => 'Current Outline 与来源 Blueprint 的字数合同不一致。',
                ]);
            }

            return [...$recomputed, 'source' => 'outline_blueprint', 'frozen' => true];
        }

        $chapterTargetWords = (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000);
        $base = $this->freeze((int) $novel->target_words, $chapterTargetWords, $outline->volumes()->count());

        return [
            ...$base,
            'source' => 'current_novel_settings',
            'frozen' => false,
            'volumes' => $outline->volumes()->get()->map(fn ($volume): array => [
                'volume_key' => $volume->volume_key,
                'target_words' => (int) $volume->target_words,
                'target_chapter_count' => $this->targetChapterCount((int) $volume->target_words, $chapterTargetWords),
            ])->all(),
        ];
    }

    /** @return array<string, int> */
    private function baseFrom(array $contract): array
    {
        return [
            'schema_version' => (int) ($contract['schema_version'] ?? 0),
            'novel_target_words' => (int) ($contract['novel_target_words'] ?? 0),
            'chapter_target_words' => (int) ($contract['chapter_target_words'] ?? 0),
            'target_chapter_count' => (int) ($contract['target_chapter_count'] ?? 0),
            'requested_volume_count' => (int) ($contract['requested_volume_count'] ?? 0),
        ];
    }

    private function assertBase(array $contract): void
    {
        $expected = $this->freeze(
            (int) ($contract['novel_target_words'] ?? 0),
            (int) ($contract['chapter_target_words'] ?? 0),
            (int) ($contract['requested_volume_count'] ?? 0),
        );
        if ($this->baseFrom($contract) !== $expected) {
            throw ValidationException::withMessages(['length_contract' => '字数合同版本或派生章节数无效。']);
        }
    }

    private function targetChapterCount(int $targetWords, int $chapterTargetWords): int
    {
        return max(1, (int) ceil($targetWords / $chapterTargetWords));
    }

    private function semanticHash(array $value): string
    {
        $normalize = function (mixed $item) use (&$normalize): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }

            return array_map($normalize, $item);
        };

        return hash('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
