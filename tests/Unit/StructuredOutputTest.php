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

test('truncated structured output is classified as retryable', function () {
    try {
        StructuredOutput::require(
            structuredOutputResponse(null, metadata: ['finish_reason' => 'length']),
            'scene',
            'Scene Draft',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('scene_output_truncated')
            ->and($exception->retryable)->toBeTrue();

        return;
    }

    $this->fail('Expected a retryable truncation exception.');
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

test('truncated plain content is classified as retryable', function () {
    try {
        StructuredOutput::requireContent(
            structuredOutputResponse(null, metadata: ['finish_reason' => 'length']),
            'rewrite',
            'Chapter Rewrite',
        );
    } catch (AiProviderException $exception) {
        expect($exception->errorCode)->toBe('rewrite_output_truncated')
            ->and($exception->retryable)->toBeTrue();

        return;
    }

    $this->fail('Expected a retryable truncation exception.');
});
