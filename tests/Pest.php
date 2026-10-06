<?php

use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\GenerationStage;
use App\Enums\RunStatus;
use App\Models\AIModelPrice;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use App\Models\Novel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function attachCanonicalArtifact(Novel $novel, Chapter $chapter): GenerationArtifact
{
    $run = GenerationRun::factory()->for($novel)->for($chapter)->create([
        'stage' => GenerationStage::Commit,
        'status' => RunStatus::Succeeded,
    ]);
    $artifact = GenerationArtifact::factory()->for($run)->create([
        'type' => ArtifactType::ChapterDraft,
    ]);
    $chapter->update(['canonical_artifact_id' => $artifact->getKey()]);

    return $artifact;
}

function seedVerifiedOutlineModelProfiles(): void
{
    collect([
        AiStage::OutlineFoundation,
        AiStage::OutlineStructure,
        AiStage::OutlineArcBeats,
        AiStage::OutlineBeatDetail,
    ])->map(fn (AiStage $stage): array => [
        'provider' => strtolower((string) config("ai.stage_providers.{$stage->value}")),
        'model' => (string) config("ai.models.{$stage->value}"),
    ])->unique(fn (array $route): string => $route['provider'].'|'.$route['model'])
        ->each(function (array $route): void {
            AIModelPrice::query()->updateOrCreate([
                ...$route,
                'currency' => strtoupper((string) config('ai.cost.currency', 'USD')),
            ], [
                'billing_unit' => 1_000_000,
                'context_window_tokens' => 1_050_000,
                'max_output_tokens' => 128_000,
                'supports_structured_output' => true,
                'supports_reasoning_effort' => true,
                'input_price' => 0,
                'output_price' => 0,
                'is_enabled' => true,
            ]);
        });
}
