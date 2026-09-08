<?php

namespace App\Services;

use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Enums\StoryArcStatus;
use App\Models\GenerationArtifact;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EndingAuditService
{
    private const CONTRACT_FIELDS = [
        'final_protagonist_state' => '主角最终状态',
        'main_conflict_resolution' => '主冲突解决方式',
        'theme_payoff' => '主题兑现',
        'required_foreshadowing_payoff' => '必须回收的伏笔',
        'character_arc_requirements' => '人物弧要求',
        'allowed_open_endings' => '允许保留的开放结局',
    ];

    private const STATE_DOMAINS = [
        'characters', 'relationships', 'locations', 'items', 'world', 'timeline',
        'open_threads', 'foreshadowings', 'reader_promises',
    ];

    public function __construct(private readonly ClosureDebtService $closureDebt) {}

    public function audit(int|Novel $novel): GenerationArtifact
    {
        $novelId = $novel instanceof Novel ? $novel->getKey() : $novel;

        return DB::transaction(function () use ($novelId): GenerationArtifact {
            $novel = Novel::query()->lockForUpdate()->findOrFail($novelId);

            if ($novel->status !== NovelStatus::Completing) {
                throw ValidationException::withMessages(['ending_audit' => '只有收束中的小说可以执行结局审计。']);
            }

            $novel->load(['currentBible', 'canonicalStateVersion', 'storyArcs', 'foreshadowings']);
            $data = $this->build($novel);
            $input = collect($data)->except('audited_at')->all();
            $encoded = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $inputHash = hash('sha256', $encoded);

            $existing = $novel->generationRuns()
                ->where('stage', GenerationStage::EndingAudit)
                ->where('status', RunStatus::Succeeded)
                ->where('input_hash', $inputHash)
                ->latest('id')
                ->first();

            if ($existing !== null) {
                return $existing->artifacts()->where('type', ArtifactType::EndingAudit)->sole();
            }

            $attempt = ((int) $novel->generationRuns()->where('stage', GenerationStage::EndingAudit)->max('attempt')) + 1;
            $run = $novel->generationRuns()->create([
                'scope_type' => 'novel',
                'scope_id' => $novel->getKey(),
                'stage' => GenerationStage::EndingAudit,
                'status' => RunStatus::Succeeded,
                'attempt' => $attempt,
                'idempotency_key' => "ending-audit:{$novel->getKey()}:{$inputHash}",
                'input_hash' => $inputHash,
                'state_version' => $novel->canonicalStateVersion?->version,
                'bible_version' => $novel->currentBible?->version,
                'context_snapshot' => [
                    'state_version' => $novel->canonicalStateVersion?->version,
                    'bible_version' => $novel->currentBible?->version,
                    'closure_debt' => $this->closureDebt->calculate($novel)->toArray(),
                ],
                'started_at' => now(),
                'finished_at' => now(),
            ]);

            return $run->artifacts()->create([
                'type' => ArtifactType::EndingAudit,
                'version' => $attempt,
                'content' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data' => $data,
                'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
        });
    }

    /** @return array<string, mixed> */
    private function build(Novel $novel): array
    {
        $debt = $this->closureDebt->calculate($novel);
        $contract = $novel->currentBible?->ending_contract ?? [];
        $state = $novel->canonicalStateVersion?->state;
        $checks = [
            $this->contractCheck($contract),
            $this->check('critical_closure_debt', '关键收束债务', $debt->critical() === 0, $debt->critical() === 0
                ? ['未发现关键收束债务。']
                : collect($debt->toArray())->where('critical', true)->map(fn (array $item): string => $item['category_label'].'：'.$item['title'])->values()->all()),
            $this->check('foreshadowing', '伏笔回收', $novel->foreshadowings->every(fn ($item): bool => $item->status->isTerminal()),
                $novel->foreshadowings->reject(fn ($item): bool => $item->status->isTerminal())->map(fn ($item): string => "{$item->title}：{$item->status->getLabel()}")->values()->all() ?: ['所有伏笔均已兑现或明确放弃。']),
            $this->check('story_arc', '故事线收束', $novel->storyArcs->every(fn ($arc): bool => $arc->status === StoryArcStatus::Completed),
                $novel->storyArcs->reject(fn ($arc): bool => $arc->status === StoryArcStatus::Completed)->map(fn ($arc): string => "{$arc->title}：{$arc->status->getLabel()}")->values()->all() ?: ['所有故事线均已完成。']),
            $this->characterArcCheck($contract, is_array($state) ? $state : []),
            $this->stateGapCheck($state),
        ];

        return [
            'decision' => collect($checks)->contains(fn (array $check): bool => $check['status'] === 'BLOCK') ? 'BLOCK' : 'PASS',
            'audited_at' => now()->toISOString(),
            'state_version' => $novel->canonicalStateVersion?->version,
            'bible_version' => $novel->currentBible?->version,
            'checks' => $checks,
        ];
    }

    /** @param array<string, mixed> $contract */
    private function contractCheck(array $contract): array
    {
        $missing = collect(self::CONTRACT_FIELDS)->filter(fn (string $label, string $key): bool => blank($contract[$key] ?? null));
        $evidence = $missing->isEmpty()
            ? collect(self::CONTRACT_FIELDS)->map(fn (string $label, string $key): string => $label.'：'.$this->summary($contract[$key]))->values()->all()
            : $missing->map(fn (string $label): string => $label.'：未定义')->values()->all();

        return $this->check('ending_contract', '结局契约', $missing->isEmpty(), $evidence);
    }

    /** @param array<string, mixed> $contract
     * @param  array<string, mixed>  $state
     */
    private function characterArcCheck(array $contract, array $state): array
    {
        $requirements = collect($contract['character_arc_requirements'] ?? [])->filter(fn ($value): bool => filled($value))->values();
        $completed = collect(data_get($state, 'character_arcs', []))->filter(fn ($arc): bool => is_array($arc) && in_array(strtolower((string) ($arc['status'] ?? '')), ['completed', 'resolved', 'fulfilled'], true));
        $passed = $requirements->isNotEmpty() && $completed->count() >= $requirements->count();
        $evidence = $requirements->isEmpty()
            ? ['结局契约未定义人物弧要求。']
            : $requirements->map(fn (string $requirement, int $index): string => $requirement.'：'.($completed->values()->has($index) ? '已完成' : '缺少完成证据'))->all();

        return $this->check('character_arc', '人物弧完成度', $passed, $evidence);
    }

    private function stateGapCheck(mixed $state): array
    {
        if (! is_array($state)) {
            return $this->check('state_gaps', '故事状态完整性', false, ['当前正式故事状态不存在。']);
        }

        $missing = collect(self::STATE_DOMAINS)->reject(fn (string $domain): bool => array_key_exists($domain, $state))->values();

        return $this->check('state_gaps', '故事状态完整性', $missing->isEmpty(), $missing->isEmpty()
            ? ['正式故事状态的基础领域完整。']
            : $missing->map(fn (string $domain): string => "缺少领域：{$domain}")->all());
    }

    /** @param array<int, string> $evidence
     * @return array<string, mixed>
     */
    private function check(string $key, string $label, bool $passed, array $evidence): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $passed ? 'PASS' : 'BLOCK', 'evidence' => $evidence];
    }

    private function summary(mixed $value): string
    {
        if (! is_array($value)) {
            return (string) $value;
        }

        return collect($value)
            ->map(fn (mixed $item): string => is_scalar($item) ? (string) $item : json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
            ->join('；');
    }
}
