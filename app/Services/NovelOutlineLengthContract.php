<?php

namespace App\Services;

use App\Data\NormalizedNovelOutline;
use App\Exceptions\OutlineLengthContractException;
use App\Models\Novel;

/**
 * 冻结并核对全书字数、分卷字数、主线 Beat 章节预算与单章目标之间的换算关系。
 */
final class NovelOutlineLengthContract
{
    public const SCHEMA_VERSION = 2;

    private const LEGACY_SCHEMA_VERSION = 1;

    /** @return array<string, mixed> */
    public function freeze(int $novelTargetWords, int $chapterTargetWords, int $volumeCount): array
    {
        if ($novelTargetWords < 1 || $chapterTargetWords < 1 || $volumeCount < 1) {
            throw OutlineLengthContractException::withMessages([
                'length_contract' => '小说目标字数、单章目标字数和预计分卷数必须是正整数。',
            ]);
        }
        $targetChapterCount = (int) ceil($novelTargetWords / $chapterTargetWords);
        if ($targetChapterCount < $volumeCount) {
            throw OutlineLengthContractException::withMessages([
                'length_contract' => "全书约 {$targetChapterCount} 章，少于预计分卷数 {$volumeCount}，无法为每卷分配至少一章。",
            ]);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'novel_target_words' => $novelTargetWords,
            'chapter_target_words' => $chapterTargetWords,
            'target_chapter_count' => $targetChapterCount,
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
            throw OutlineLengthContractException::withMessages([
                'length_contract' => 'Structure 的分卷数与冻结合同不一致。',
            ]);
        }

        $total = array_sum(array_map(
            static fn (mixed $volume): int => is_array($volume) ? (int) ($volume['target_words'] ?? 0) : 0,
            $volumes,
        ));
        if ($total !== (int) $frozen['novel_target_words']) {
            throw OutlineLengthContractException::withMessages([
                'length_contract' => "各 Volume target_words 合计 {$total}，必须等于冻结的小说目标字数 {$frozen['novel_target_words']}。",
            ]);
        }
        $targetChapterCount = (int) $frozen['target_chapter_count'];
        if ((int) $frozen['schema_version'] !== self::LEGACY_SCHEMA_VERSION && $targetChapterCount < count($volumes)) {
            throw OutlineLengthContractException::withMessages([
                'length_contract' => "全书约 {$targetChapterCount} 章，无法为 ".count($volumes).' 个 Volume 各分配至少一章。',
            ]);
        }
        $volumeChapterTargets = (int) $frozen['schema_version'] === self::LEGACY_SCHEMA_VERSION
            ? array_map(
                fn (array $volume): int => $this->targetChapterCount((int) $volume['target_words'], (int) $frozen['chapter_target_words']),
                $volumes,
            )
            : $this->allocateTargets(
                array_map(static fn (array $volume): int => (int) $volume['target_words'], $volumes),
                $targetChapterCount,
            );

        return [
            ...$frozen,
            'volumes' => array_map(fn (array $volume, int $index): array => [
                'volume_key' => (string) $volume['key'],
                'target_words' => (int) $volume['target_words'],
                'target_chapter_count' => $volumeChapterTargets[$index],
            ], $volumes, array_keys($volumes)),
        ];
    }

    /**
     * Structure 同时看得到一卷内的全部 Arc，因此由它声明相对篇幅权重；Laravel
     * 再把权重确定性换算为每个 Main Arc 的精确章节目标，避免隔离的 Arc 请求各自
     * 按整卷章节数编制预算。
     *
     * @return array<string, mixed>
     */
    public function allocateStructure(array $structure, array $frozen): array
    {
        $contract = $this->validateStructure($structure, $frozen);

        foreach ($structure['volumes'] as $volumeIndex => $volume) {
            $mainArcs = array_values(array_filter(
                $volume['arcs'] ?? [],
                static fn (mixed $arc): bool => is_array($arc) && ($arc['type'] ?? null) === 'main',
            ));
            if ($mainArcs === []) {
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 至少需要一个 Main Arc 承载章节预算。",
                ]);
            }

            $target = (int) $contract['volumes'][$volumeIndex]['target_chapter_count'];
            if ($target < count($mainArcs)) {
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 仅约 {$target} 章，无法为 ".count($mainArcs).' 个 Main Arc 各分配至少一章。',
                ]);
            }

            $weights = array_map(static fn (array $arc): int => (int) ($arc['chapter_weight'] ?? 0), $mainArcs);
            $weightTotal = array_sum($weights);
            if ($weightTotal < 1) {
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 的 Main Arc 缺少有效 chapter_weight。",
                ]);
            }

            $assigned = $this->allocateTargets($weights, $target);

            $contract['volumes'][$volumeIndex]['main_arc_allocations'] = array_map(
                static fn (array $arc, int $arcIndex): array => [
                    'arc_key' => (string) $arc['key'],
                    'chapter_weight' => (int) $arc['chapter_weight'],
                    'target_chapter_count' => $assigned[$arcIndex],
                ],
                $mainArcs,
                array_keys($mainArcs),
            );
        }

        return $contract;
    }

    /** 校验一个隔离 Arc 的 Beat 预算是否覆盖 Structure 已分配的本地章节目标。 */
    public function validateArcBeats(array $arcBeats, string $arcKey, array $allocatedContract): void
    {
        $allocation = null;
        foreach ($allocatedContract['volumes'] ?? [] as $volume) {
            foreach ($volume['main_arc_allocations'] ?? [] as $candidate) {
                if (($candidate['arc_key'] ?? null) === $arcKey) {
                    $allocation = $candidate;
                    break 2;
                }
            }
        }

        // Subplot 与主线共用章节，不单独计入全书章节总量。
        if ($allocation === null) {
            return;
        }

        $minimum = 0;
        $maximum = 0;
        $openMaximum = false;
        foreach ($arcBeats['beats'] ?? [] as $beat) {
            $minimum += (int) data_get($beat, 'chapter_budget.min', 0);
            $beatMaximum = data_get($beat, 'chapter_budget.max');
            if ($beatMaximum === null) {
                $openMaximum = true;
            } else {
                $maximum += (int) $beatMaximum;
            }
        }

        $target = (int) $allocation['target_chapter_count'];
        $upper = $openMaximum ? null : $maximum;
        if ($minimum > $target || ($upper !== null && $upper < $target)) {
            $range = $upper === null ? "{$minimum} 章以上" : "{$minimum} 至 {$upper} 章";
            throw OutlineLengthContractException::withMessages([
                'length_contract' => "Main Arc {$arcKey} 分配目标为 {$target} 章，但 Beat 预算合计为 {$range}。",
            ]);
        }
    }

    /**
     * Skeleton 完成后才能跨 Arc 汇总主线 Beat 预算；支线可与主线共用章节，不重复计入卷章节数。
     *
     * @return array<string, mixed>
     */
    public function complete(array $outline, array $frozen, ?array $allocatedContract = null): array
    {
        $validated = $this->validateStructure($outline, $frozen);
        $contract = $allocatedContract ?? $validated;
        if ($this->baseFrom($contract) !== $this->baseFrom($validated)) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '已分配字数合同与冻结基础合同不一致。']);
        }
        foreach ($validated['volumes'] as $index => $volume) {
            if ((int) data_get($contract, "volumes.{$index}.target_words") !== $volume['target_words']
                || (int) data_get($contract, "volumes.{$index}.target_chapter_count") !== $volume['target_chapter_count']) {
                throw OutlineLengthContractException::withMessages(['length_contract' => '已分配字数合同的分卷目标与冻结基础合同不一致。']);
            }
        }
        $this->assertAllocationMatchesStructure($outline, $contract);
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
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 缺少可承载章节预算的 Main Beat。",
                ]);
            }

            $target = (int) $contract['volumes'][$index]['target_chapter_count'];
            $upper = $hasOpenMaximum ? null : $maximum;
            if ($minimum > $target || ($upper !== null && $upper < $target)) {
                $range = $upper === null ? "{$minimum} 章以上" : "{$minimum} 至 {$upper} 章";
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => "Volume {$volume['key']} 按字数折算需约 {$target} 章，但 Main Beat 预算合计为 {$range}。",
                ]);
            }

            $contract['volumes'][$index]['mainline_beat_count'] = $mainBeatCount;
            $contract['volumes'][$index]['mainline_chapter_budget'] = [
                'min' => $minimum,
                'max' => $upper,
            ];

            foreach ($contract['volumes'][$index]['main_arc_allocations'] ?? [] as $allocation) {
                $arc = collect($volume['arcs'] ?? [])->firstWhere('key', $allocation['arc_key']);
                if (! is_array($arc)) {
                    throw OutlineLengthContractException::withMessages([
                        'length_contract' => "找不到已分配预算的 Main Arc {$allocation['arc_key']}。",
                    ]);
                }
                $this->validateArcBeats(['beats' => $arc['beats'] ?? []], (string) $allocation['arc_key'], $contract);
            }
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
            throw OutlineLengthContractException::withMessages(['length_contract' => '缺少 Current Outline，无法解析字数合同。']);
        }

        $stored = data_get($outline->sourceArtifact?->data, 'length_contract');
        if (is_array($stored)) {
            $outlineData = NormalizedNovelOutline::fromModel($outline)->toArray();
            $recomputed = $this->complete(
                $outlineData,
                $this->baseFrom($stored),
                $this->hasArcAllocations($stored) ? $stored : null,
            );
            if ($this->semanticHash($recomputed) !== $this->semanticHash($stored)) {
                throw OutlineLengthContractException::withMessages([
                    'length_contract' => 'Current Outline 与来源 Blueprint 的字数合同不一致。',
                ]);
            }

            return [...$recomputed, 'source' => 'outline_blueprint', 'frozen' => true];
        }

        $chapterTargetWords = (int) data_get($novel->settings, 'generation.chapter_target_words', 3_000);
        $base = [
            'schema_version' => self::LEGACY_SCHEMA_VERSION,
            'novel_target_words' => (int) $novel->target_words,
            'chapter_target_words' => $chapterTargetWords,
            'target_chapter_count' => $this->targetChapterCount((int) $novel->target_words, $chapterTargetWords),
            'requested_volume_count' => $outline->volumes()->count(),
        ];

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
        $version = (int) ($contract['schema_version'] ?? 0);
        if (! in_array($version, [self::LEGACY_SCHEMA_VERSION, self::SCHEMA_VERSION], true)) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同版本无效。']);
        }
        $novelTargetWords = (int) ($contract['novel_target_words'] ?? 0);
        $chapterTargetWords = (int) ($contract['chapter_target_words'] ?? 0);
        $volumeCount = (int) ($contract['requested_volume_count'] ?? 0);
        if ($novelTargetWords < 1 || $chapterTargetWords < 1 || $volumeCount < 1) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同的基础数值无效。']);
        }
        $targetChapterCount = (int) ceil($novelTargetWords / $chapterTargetWords);
        if ($version === self::SCHEMA_VERSION && $targetChapterCount < $volumeCount) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同无法为每个 Volume 分配至少一章。']);
        }
        $expected = [
            'schema_version' => $version,
            'novel_target_words' => $novelTargetWords,
            'chapter_target_words' => $chapterTargetWords,
            'target_chapter_count' => $targetChapterCount,
            'requested_volume_count' => $volumeCount,
        ];
        if ($this->baseFrom($contract) !== $expected) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同版本或派生章节数无效。']);
        }
    }

    private function targetChapterCount(int $targetWords, int $chapterTargetWords): int
    {
        return max(1, (int) ceil($targetWords / $chapterTargetWords));
    }

    private function assertAllocationMatchesStructure(array $structure, array $contract): void
    {
        foreach ($contract['volumes'] ?? [] as $index => $volumeContract) {
            if (($volumeContract['volume_key'] ?? null) !== data_get($structure, "volumes.{$index}.key")) {
                throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同的分卷顺序与 Structure 不一致。']);
            }
            if ((int) ($volumeContract['target_words'] ?? 0) !== (int) data_get($structure, "volumes.{$index}.target_words")) {
                throw OutlineLengthContractException::withMessages(['length_contract' => '字数合同的分卷字数或章节数与 Structure 不一致。']);
            }

            if (! array_key_exists('main_arc_allocations', $volumeContract)) {
                continue;
            }
            $expectedArcKeys = collect(data_get($structure, "volumes.{$index}.arcs", []))
                ->where('type', 'main')
                ->pluck('key')
                ->values()
                ->all();
            $allocations = array_values((array) $volumeContract['main_arc_allocations']);
            if (array_column($allocations, 'arc_key') !== $expectedArcKeys
                || collect($allocations)->contains(fn (mixed $allocation): bool => ! is_array($allocation)
                    || (int) ($allocation['chapter_weight'] ?? 0) < 1
                    || (int) ($allocation['target_chapter_count'] ?? 0) < 1)
                || array_sum(array_map(static fn (array $allocation): int => (int) $allocation['target_chapter_count'], $allocations))
                    !== (int) $volumeContract['target_chapter_count']) {
                throw OutlineLengthContractException::withMessages(['length_contract' => 'Main Arc 章节分配与 Structure 或分卷目标不一致。']);
            }
        }

        if ((int) $contract['schema_version'] !== self::LEGACY_SCHEMA_VERSION && array_sum(array_map(
            static fn (array $volume): int => (int) ($volume['target_chapter_count'] ?? 0),
            $contract['volumes'] ?? [],
        )) !== (int) $contract['target_chapter_count']) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '各 Volume 章节目标合计与全书目标章节数不一致。']);
        }
    }

    private function hasArcAllocations(array $contract): bool
    {
        return collect($contract['volumes'] ?? [])->contains(
            static fn (mixed $volume): bool => is_array($volume) && array_key_exists('main_arc_allocations', $volume),
        );
    }

    /**
     * 每个节点先保留一章，再按权重使用最大余数法分配剩余章节；同余数按原顺序稳定决胜。
     *
     * @param  array<int, int>  $weights
     * @return array<int, int>
     */
    private function allocateTargets(array $weights, int $target): array
    {
        if ($weights === [] || $target < count($weights) || array_sum($weights) < 1) {
            throw OutlineLengthContractException::withMessages(['length_contract' => '章节目标无法按当前节点权重完成分配。']);
        }

        $remaining = $target - count($weights);
        $weightTotal = array_sum($weights);
        $assigned = [];
        $remainders = [];
        foreach ($weights as $index => $weight) {
            $numerator = $remaining * $weight;
            $assigned[$index] = 1 + intdiv($numerator, $weightTotal);
            $remainders[$index] = $numerator % $weightTotal;
        }

        $unassigned = $target - array_sum($assigned);
        $order = array_keys($weights);
        usort($order, static fn (int $left, int $right): int => ($remainders[$right] <=> $remainders[$left]) ?: ($left <=> $right));
        for ($index = 0; $index < $unassigned; $index++) {
            $assigned[$order[$index]]++;
        }

        return $assigned;
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
