<?php

use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\StructuredOutput;

function structuredOutputResponse(?array $data, string $content = '', array $metadata = []): AiResponse
{
    return new AiResponse(
        content: $content,
        structuredData: $data,
        inputTokens: 10,
        outputTokens: 10,
        cachedTokens: 0,
        latencyMs: 10,
        providerRequestId: 'structured-output-test',
        model: 'test-model',
        metadata: $metadata,
    );
}

test('structured output is returned unchanged', function () {
    expect(StructuredOutput::require(
        structuredOutputResponse(['ok' => true]),
        'scene',
        'Scene Draft',
    ))->toBe(['ok' => true]);
});

test('length finish reason rejects even a parsed structured object', function () {
    expect(fn () => StructuredOutput::require(
        structuredOutputResponse(['partial' => true], content: '{"partial":true}', metadata: [
            'finish_reason' => 'length',
            'completion_limit_reason' => 'visible_output_truncated',
        ]),
        'scene',
        'Scene Draft',
    ))->toThrow(AiProviderException::class, '可见输出');
});

test('visible structured output truncation is classified separately from reasoning exhaustion', function () {
    try {
        StructuredOutput::require(
            structuredOutputResponse(null, content: '{"partial":', metadata: [
                'finish_reason' => 'length',
                'completion_limit_reason' => 'visible_output_truncated',
            ]),
            'scene',
            'Scene Draft',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('scene_output_truncated')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('可见输出')
            ->and($exception->getMessage())->not->toContain('将按技术故障重试');

        return;
    }

    $this->fail('Expected a classified truncation exception.');
});

test('reasoning budget exhaustion is terminal and does not claim visible output was truncated', function () {
    try {
        StructuredOutput::require(
            structuredOutputResponse(null, metadata: [
                'finish_reason' => 'length',
                'completion_limit_reason' => 'reasoning_budget_exhausted',
            ]),
            'scene',
            'Scene Draft',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('scene_reasoning_budget_exhausted')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('生成可见')
            ->and($exception->getMessage())->not->toContain('被截断');

        return;
    }

    $this->fail('Expected reasoning budget exhaustion exception.');
});

test('completion limit without evidence remains explicitly unclassified', function () {
    try {
        StructuredOutput::require(
            structuredOutputResponse(null, metadata: ['finish_reason' => 'length']),
            'scene',
            'Scene Draft',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('scene_completion_budget_exhausted')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('未提供足够信息');

        return;
    }

    $this->fail('Expected unclassified completion budget exception.');
});

test('schema invalid and refusal remain terminal with distinct codes', function () {
    foreach ([
        [[], 'scene_schema_invalid'],
        [['refusal' => 'cannot comply'], 'scene_refused'],
    ] as [$metadata, $expectedCode]) {
        try {
            StructuredOutput::require(
                structuredOutputResponse(null, metadata: $metadata),
                'scene',
                'Scene Draft',
            );
        } catch (AiProviderException $exception) {
            expect($exception->errorCode)->toBe($expectedCode)
                ->and($exception->retryable)->toBeFalse();

            continue;
        }

        $this->fail("Expected {$expectedCode} exception.");
    }
});

test('truncated plain content is rejected even when partial text exists', function () {
    try {
        StructuredOutput::requireContent(
            structuredOutputResponse(null, content: 'partial chapter', metadata: [
                'finish_reason' => 'length',
                'completion_limit_reason' => 'visible_output_truncated',
            ]),
            'rewrite',
            'Chapter Rewrite',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('rewrite_output_truncated')
            ->and($exception->retryable)->toBeFalse();

        return;
    }

    $this->fail('Expected a classified truncation exception.');
});
