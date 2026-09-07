<?php

namespace App\Services;

use App\AI\Exceptions\AiProviderException;
use App\Data\MemoryQuery;
use App\Enums\MemoryStatus;
use App\Enums\MemoryType;
use App\Models\Memory;
use App\Models\Novel;
use InvalidArgumentException;

class MemoryRetrievalEvaluator
{
    public function __construct(private readonly MemoryRetriever $retriever) {}

    /** @return array<int, array<string, mixed>> */
    public function evaluate(int $novelId): array
    {
        Novel::query()->findOrFail($novelId);

        return collect($this->cases($novelId))
            ->map(fn (array $case): array => $this->evaluateCase($novelId, $case))
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function cases(int $novelId): array
    {
        $active = fn (MemoryType $type): ?Memory => Memory::query()
            ->where('novel_id', $novelId)
            ->where('status', MemoryStatus::Active)
            ->where('type', $type)
            ->whereNotNull('embedding')
            ->where('embedding_model', config('ai.embedding.model'))
            ->orderBy('valid_from_chapter')
            ->orderBy('id')
            ->first();

        return [
            $this->expectedCase('early_fact', '早期关键事实', $active(MemoryType::Event)),
            $this->expectedCase('early_foreshadowing', '早期伏笔', $active(MemoryType::Foreshadowing)),
            $this->expectedCase('knowledge_boundary', '人物知识边界', $active(MemoryType::CharacterMilestone)),
            $this->expectedCase('item_ownership', '物品归属', $active(MemoryType::Item)),
            $this->forbiddenCase('invalid_memory', '失效记忆', Memory::query()
                ->where('novel_id', $novelId)
                ->where('status', MemoryStatus::Invalid)
                ->whereNotNull('embedding')
                ->orderBy('id')
                ->first()),
            $this->forbiddenCase('wrong_novel', '其他小说记忆', Memory::query()
                ->where('novel_id', '!=', $novelId)
                ->where('status', MemoryStatus::Active)
                ->whereNotNull('embedding')
                ->orderBy('id')
                ->first()),
        ];
    }

    /** @return array<string, mixed> */
    private function expectedCase(string $key, string $label, ?Memory $memory): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'target' => $memory,
            'expected_ids' => $memory === null ? [] : [$memory->getKey()],
            'forbidden_ids' => [],
            'types' => $memory === null ? [] : [$memory->type],
        ];
    }

    /** @return array<string, mixed> */
    private function forbiddenCase(string $key, string $label, ?Memory $memory): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'target' => $memory,
            'expected_ids' => [],
            'forbidden_ids' => $memory === null ? [] : [$memory->getKey()],
            'types' => [],
        ];
    }

    /** @param array<string, mixed> $case @return array<string, mixed> */
    private function evaluateCase(int $novelId, array $case): array
    {
        /** @var Memory|null $target */
        $target = $case['target'];

        if ($target === null) {
            return $this->result($case, [], null, false, '未配置', '当前小说缺少该案例所需的 Memory 样本。');
        }

        try {
            $selectedIds = $this->retriever->retrieve(new MemoryQuery(
                novelId: $novelId,
                queryText: $target->summary,
                types: $case['types'],
                candidateK: (int) config('context.memory_candidate_k', 30),
                finalK: (int) config('context.memory_final_k', 10),
                tokenBudget: (int) config('context.long_term_memory_token_budget', 1_500),
            ))->where('selected', true)->map(fn ($result): int => $result->memory->getKey())->values()->all();
        } catch (AiProviderException|InvalidArgumentException $exception) {
            return $this->result($case, [], null, false, '错误', $exception->getMessage());
        }

        $hit = $case['expected_ids'] === []
            ? null
            : collect($case['expected_ids'])->every(fn (int $id): bool => in_array($id, $selectedIds, true));
        $leakage = collect($case['forbidden_ids'])->contains(fn (int $id): bool => in_array($id, $selectedIds, true));
        $status = $leakage ? '泄漏' : ($hit === false ? '未命中' : '通过');

        return $this->result($case, $selectedIds, $hit, $leakage, $status);
    }

    /** @param array<string, mixed> $case @param array<int, int> $selectedIds @return array<string, mixed> */
    private function result(array $case, array $selectedIds, ?bool $hit, bool $leakage, string $status, ?string $message = null): array
    {
        /** @var Memory|null $target */
        $target = $case['target'];

        return [
            'case' => $case['label'],
            'query' => $target?->summary ?? '—',
            'expected' => $target === null ? '—' : '#'.$target->getKey().' · '.$target->summary,
            'actual' => $selectedIds === [] ? '无' : collect($selectedIds)->map(fn (int $id): string => '#'.$id)->join('、'),
            'hit' => $hit === null ? '不适用' : ($hit ? '是' : '否'),
            'leakage' => $leakage ? '是' : '否',
            'status' => $status,
            'message' => $message,
        ];
    }
}
