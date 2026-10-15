<?php

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\Providers\FakeAiProvider;
use App\AI\Providers\ReproduciblePromptAiProvider;
use App\Enums\RunStatus;
use App\Models\GenerationRun;
use App\Services\PromptExecutionContract;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('provider freezes the exact versioned prompt and rejects drift on the same run', function () {
    $run = GenerationRun::factory()->create([
        'status' => RunStatus::Running,
        'provider' => 'openai',
        'model_policy' => 'test-model',
        'prompt_version' => 'test-prompt-v1',
    ]);
    $response = new AiResponse('ok', null, 1, 1, 0, 1, 'request', 'test-model');
    $fake = (new FakeAiProvider)->enqueue($response);
    $provider = new ReproduciblePromptAiProvider($fake, app(PromptExecutionContract::class));
    $request = new AiRequest(
        model: 'test-model', provider: 'openai', systemPrompt: '固定系统提示', prompt: '动态输入',
        promptVersion: 'test-prompt-v1', metadata: ['generation_run_id' => $run->getKey()],
    );

    expect($provider->generate($request))->toBe($response)
        ->and(data_get($run->fresh()->context_snapshot, 'prompt_execution.prompt_version'))->toBe('test-prompt-v1')
        ->and(data_get($run->fresh()->context_snapshot, 'prompt_execution.system_prompt'))->toBe('固定系统提示')
        ->and(data_get($run->fresh()->context_snapshot, 'prompt_execution.checksum'))->toHaveLength(64);

    expect(fn () => $provider->generate(new AiRequest(
        model: 'test-model', provider: 'openai', systemPrompt: '已经变化', prompt: '动态输入',
        promptVersion: 'test-prompt-v1', metadata: ['generation_run_id' => $run->getKey()],
    )))->toThrow(AiProviderException::class, 'Prompt 内容发生变化');
});
