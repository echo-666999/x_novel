<?php

use App\AI\AiSettingsResolver;
use App\Enums\AiStage;
use App\Models\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;

uses(RefreshDatabase::class);

test('every ai stage resolves from its global stage model', function () {
    foreach (AiStage::cases() as $stage) {
        config()->set("ai.models.{$stage->value}", "global-{$stage->value}-model");
    }

    $resolver = app(AiSettingsResolver::class);

    foreach (AiStage::cases() as $stage) {
        $resolved = $resolver->resolve($stage);

        expect($resolved->stage)->toBe($stage)
            ->and($resolved->model)->toBe("global-{$stage->value}-model")
            ->and($resolved->source)->toBe('global');
    }
});

test('novel model overrides take precedence and blank overrides inherit global settings', function () {
    config()->set('ai.models.writer', 'global-writer');
    config()->set('ai.models.reviewer', 'global-reviewer');
    $novel = Novel::factory()->create([
        'settings' => [
            'temperature' => 0.4,
            'ai' => [
                'models' => [
                    'writer' => ' novel-writer ',
                    'reviewer' => ' ',
                ],
            ],
        ],
    ]);

    $resolver = app(AiSettingsResolver::class);

    expect($resolver->resolve(AiStage::Writer, $novel))
        ->model->toBe('novel-writer')
        ->source->toBe('novel')
        ->and($resolver->resolve('reviewer', $novel))
        ->model->toBe('global-reviewer')
        ->source->toBe('global');
});

test('stage resolution falls back to the global default model', function () {
    config()->set('ai.models.summary', '');
    config()->set('ai.model', 'fallback-model');

    expect(app(AiSettingsResolver::class)->modelFor(AiStage::Summary))->toBe('fallback-model');
});

test('unknown ai stages are rejected', function () {
    app(AiSettingsResolver::class)->resolve('unknown');
})->throws(InvalidArgumentException::class, 'Unsupported AI stage.');
