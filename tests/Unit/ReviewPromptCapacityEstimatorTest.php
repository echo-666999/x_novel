<?php

use App\AI\Data\AiRequest;
use App\Services\ReviewPromptCapacityEstimator;
use App\Services\TokenBudget;

test('review capacity estimator counts the complete request instead of only the context payload', function () {
    $tokenBudget = new TokenBudget;
    $estimator = new ReviewPromptCapacityEstimator($tokenBudget);
    $context = [
        'chapter_id' => 2,
        'draft' => ['content' => str_repeat('正文内容。', 100)],
    ];
    $contractCounts = array_fill_keys([
        'milestone_completion',
        'beat_exit',
        'handoff_readiness',
        'foreshadowing_audits',
        'arc_beat_audits',
        'arc_completion_audits',
        'character_candidate_audits',
        'world_entity_candidate_audits',
    ], 0);
    $request = $estimator->request(
        context: $context,
        contractCounts: $contractCounts,
        provider: 'openai',
        model: 'review-test',
        reasoningEffort: 'high',
        promptVersion: 'reviewer-v20+natural-reviewing-v3',
        maxCompletionTokens: 20_000,
    );
    $estimate = $estimator->estimate($request);
    $completeInput = [
        'messages' => $request->resolvedMessages(),
        'response_schema' => $request->responseSchema,
    ];

    expect($estimate['estimated_input_tokens'])->toBe($tokenBudget->estimate($completeInput))
        ->and($request->resolvedMessages()[0]['role'])->toBe('system')
        ->and($request->resolvedMessages()[1]['role'])->toBe('user')
        ->and($estimate['input_breakdown']['system_prompt_tokens'])->toBeGreaterThan(0)
        ->and($estimate['input_breakdown']['user_instruction_tokens'])->toBeGreaterThan(0)
        ->and($estimate['input_breakdown']['user_payload_tokens'])->toBeGreaterThan(0)
        ->and($estimate['input_breakdown']['serialized_messages_tokens'])->toBeGreaterThan(0)
        ->and($estimate['input_breakdown']['response_schema_tokens'])->toBeGreaterThan(0);

    $withoutSystemPrompt = new AiRequest(
        model: $request->model,
        provider: $request->provider,
        prompt: $request->prompt,
        maxTokens: $request->maxTokens,
        reasoningEffort: $request->reasoningEffort,
        responseSchema: $request->responseSchema,
        promptVersion: $request->promptVersion,
    );
    $withoutUserInstruction = new AiRequest(
        model: $request->model,
        provider: $request->provider,
        systemPrompt: $request->systemPrompt,
        prompt: json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        maxTokens: $request->maxTokens,
        reasoningEffort: $request->reasoningEffort,
        responseSchema: $request->responseSchema,
        promptVersion: $request->promptVersion,
    );
    $withoutResponseSchema = new AiRequest(
        model: $request->model,
        provider: $request->provider,
        systemPrompt: $request->systemPrompt,
        prompt: $request->prompt,
        maxTokens: $request->maxTokens,
        reasoningEffort: $request->reasoningEffort,
        promptVersion: $request->promptVersion,
    );

    expect($estimate['estimated_input_tokens'])
        ->toBeGreaterThan($estimator->estimate($withoutSystemPrompt)['estimated_input_tokens'])
        ->toBeGreaterThan($estimator->estimate($withoutUserInstruction)['estimated_input_tokens'])
        ->toBeGreaterThan($estimator->estimate($withoutResponseSchema)['estimated_input_tokens']);
});
