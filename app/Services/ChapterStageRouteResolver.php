<?php

namespace App\Services;

use App\AI\Data\ResolvedAiSettings;
use App\Enums\AiStage;
use App\Models\Chapter;
use App\Models\GenerationArtifact;

/** 统一解析普通 Admission 与 Admission v1 显式恢复合同中的阶段路由。 */
final class ChapterStageRouteResolver
{
    public function __construct(
        private readonly PlanAdmissionService $planAdmission,
        private readonly GenerationOutputCapacityGuard $outputCapacity,
        private readonly LegacyAdmissionRecoveryContract $legacyRecovery,
    ) {}

    /**
     * @return array{settings: ResolvedAiSettings, prompt_version: string, route: array<string, mixed>, contract_source: string, recovery_contract_run_id: int|null}
     */
    public function resolve(Chapter $chapter, AiStage $stage, GenerationArtifact $source): array
    {
        if ($this->legacyRecovery->appliesTo($chapter)) {
            $resolved = $this->legacyRecovery->routeFor($chapter, $stage, $source);
            $route = $resolved['route'];
            $settings = new ResolvedAiSettings(
                stage: $stage,
                provider: (string) $route['provider'],
                model: (string) $route['model'],
                reasoningEffort: $route['reasoning_effort'] ?? null,
                source: LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
            );

            return [
                'settings' => $settings,
                'prompt_version' => (string) $route['prompt_version'],
                'route' => $route,
                'contract_source' => LegacyAdmissionRecoveryContract::CONTRACT_VERSION,
                'recovery_contract_run_id' => $resolved['run']->getKey(),
            ];
        }

        $plan = $chapter->latestPlan;
        $settings = $this->planAdmission->historicalRouteFor($plan, $stage);

        return [
            'settings' => $settings,
            'prompt_version' => $this->planAdmission->historicalPromptVersionFor($plan, $stage),
            'route' => $this->outputCapacity->frozenRoute($chapter, $stage),
            'contract_source' => 'plan_admission',
            'recovery_contract_run_id' => null,
        ];
    }

    /** 把合同来源写进每个后续 Run，保证 Resume 始终沿用同一份冻结路由。 */
    public function generationPreferences(array $resolved): array
    {
        $settings = $resolved['settings'];
        $route = $resolved['route'];

        return [
            'frozen_route' => [
                'provider' => $settings->provider,
                'model' => $settings->model,
                'reasoning_effort' => $settings->reasoningEffort,
                'source' => $settings->source,
                'prompt_version' => $resolved['prompt_version'],
            ],
            'model_capacity' => $route['model_capacity'],
            'request_budgets' => $route['request_budgets'],
            'repair_request_budgets' => $route['repair_request_budgets'],
            'route_contract_source' => $resolved['contract_source'],
            ...($resolved['recovery_contract_run_id'] === null ? [] : [
                'recovery_contract_run_id' => $resolved['recovery_contract_run_id'],
            ]),
        ];
    }
}
