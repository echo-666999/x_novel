<?php

namespace App\Services;

use App\AI\AiSettingsResolver;
use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Exceptions\AiProviderException;
use App\AI\PromptVersionResolver;
use App\Data\StoryEventCandidate;
use App\Enums\AiStage;
use App\Enums\ArtifactType;
use App\Enums\ChapterStatus;
use App\Enums\GenerationStage;
use App\Enums\NovelStatus;
use App\Enums\RunStatus;
use App\Models\Chapter;
use App\Models\GenerationArtifact;
use App\Models\GenerationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoryEventExtractor
{
    private const STALE_RUN_SECONDS = 120;

    public function __construct(
        private readonly AiProvider $provider,
        private readonly AiSettingsResolver $settingsResolver,
        private readonly PromptVersionResolver $promptVersionResolver,
    ) {}

    public function extract(int $chapterId, bool $regenerate = false): ?GenerationArtifact
    {
        $chapter = Chapter::query()->with([
            'novel.canonicalStateVersion',
            'latestPlan',
            'scenes:id,chapter_id',
        ])->findOrFail($chapterId);

        if ($chapter->novel->status === NovelStatus::Paused) {
            throw new AiProviderException('novel_paused', '小说已暂停，不能开始 Story Event Extraction。', false);
        }

        $draft = $this->latestChapterDraft($chapter);
        $context = $this->context($chapter, $draft);
        $settings = $this->settingsResolver->resolve(AiStage::Extractor, $chapter->novel);
        $promptVersion = $this->promptVersionResolver->resolve(AiStage::Extractor);
        $inputHash = hash('sha256', json_encode([
            'context' => $context,
            'model' => $settings->model,
            'prompt_version' => $promptVersion,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $baseKey = "events:{$draft->checksum}:{$context['state_version']}:{$promptVersion}";
        [$run, $reused] = $this->startRun($chapter, $baseKey, $inputHash, $context, $settings->model, $promptVersion, $regenerate);

        if ($reused) {
            return $run->artifacts()->where('type', ArtifactType::EventCandidate)->latest('version')->first();
        }

        try {
            $response = $this->provider->generate(new AiRequest(
                model: $settings->model,
                systemPrompt: 'You are XNovel StoryEventExtractor. Identify only events that change later story state. Return structured JSON matching the schema. Every event needs traceable text evidence. Ambiguous implications must use low confidence. Never mutate canonical story data.',
                prompt: 'Extract Story Event Candidates from this chapter draft and authoritative context: '.json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                temperature: 0.2,
                maxTokens: (int) config('generation.event_extraction_max_output_tokens', 4_000),
                responseSchema: $this->responseSchema(),
                promptVersion: $promptVersion,
                metadata: [
                    'generation_run_id' => $run->getKey(),
                    'novel_id' => $chapter->novel_id,
                    'chapter_id' => $chapter->getKey(),
                    'stage' => AiStage::Extractor->value,
                ],
            ));

            if ($response->structuredData === null) {
                throw new AiProviderException('event_schema_invalid', 'AI 未返回合法的结构化 Story Event Candidates。', false);
            }

            $candidates = $this->validateCandidates($response->structuredData, $chapter, $draft);

            return $this->complete($run, $chapter, $draft, $candidates, $context['state_version']);
        } catch (Throwable $exception) {
            $this->failRun($run, $exception);

            throw $exception;
        }
    }

    public function markTerminalFailure(int $chapterId): void
    {
        Chapter::query()->whereKey($chapterId)->update(['status' => ChapterStatus::Blocked]);
    }

    /** @return array<string, mixed> */
    private function responseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['events'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => StoryEventCandidate::schema(),
                ],
            ],
        ];
    }

    private function latestChapterDraft(Chapter $chapter): GenerationArtifact
    {
        $draft = GenerationArtifact::query()
            ->whereIn('type', [ArtifactType::ChapterDraft, ArtifactType::RewriteDraft])
            ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey())->whereNull('scene_id'))
            ->orderByDesc('id')
            ->first();

        if ($draft === null) {
            throw new AiProviderException('event_input_incomplete', 'Story Event Extraction 缺少 Chapter Draft。', false);
        }

        return $draft;
    }

    /** @return array<string, mixed> */
    private function context(Chapter $chapter, GenerationArtifact $draft): array
    {
        if ($chapter->latestPlan === null || $chapter->novel->canonicalStateVersion === null) {
            throw new AiProviderException('event_context_incomplete', 'Story Event Extraction 缺少 Chapter Plan 或 Story State。', false);
        }

        return [
            'chapter_id' => $chapter->getKey(),
            'chapter_draft' => [
                'artifact_id' => $draft->getKey(),
                'checksum' => $draft->checksum,
                'content' => $draft->content,
            ],
            'chapter_plan' => $chapter->latestPlan->only([
                'id', 'version', 'chapter_function', 'arc_contribution', 'reader_promise',
                'must_reveal', 'may_hint', 'must_not_reveal', 'required_facts', 'forbidden_conflicts',
            ]),
            'state_version' => $chapter->novel->canonicalStateVersion->version,
            'current_state' => $chapter->novel->canonicalStateVersion->state,
            'locked_facts' => $chapter->novel->facts()
                ->where('locked', true)
                ->where('status', 'active')
                ->get()
                ->map->only(['id', 'subject_type', 'subject_id', 'predicate', 'value'])
                ->all(),
        ];
    }

    /** @return array{0: GenerationRun, 1: bool} */
    private function startRun(Chapter $chapter, string $baseKey, string $inputHash, array $context, string $model, string $promptVersion, bool $regenerate): array
    {
        return DB::transaction(function () use ($chapter, $baseKey, $inputHash, $context, $model, $promptVersion, $regenerate): array {
            $chapter = Chapter::query()->lockForUpdate()->findOrFail($chapter->getKey());
            $runs = $chapter->generationRuns()->where('stage', GenerationStage::EventExtraction);
            $active = $runs->clone()->whereIn('status', [RunStatus::Queued, RunStatus::Running])->latest('id')->first();

            if ($active !== null && $active->updated_at->gt(now()->subSeconds(self::STALE_RUN_SECONDS))) {
                return [$active, true];
            }

            if ($active !== null) {
                $active->update([
                    'status' => RunStatus::Failed,
                    'error_code' => 'worker_interrupted',
                    'error_message' => 'Event Extraction Run 超时未完成，已由后续投递恢复。',
                    'finished_at' => now(),
                ]);
            }

            if (! $regenerate && ($succeeded = $runs->clone()->where('input_hash', $inputHash)->where('status', RunStatus::Succeeded)->latest('id')->first())) {
                return [$succeeded, true];
            }

            $attempt = ((int) $runs->clone()->max('attempt')) + 1;

            return [GenerationRun::query()->create([
                'novel_id' => $chapter->novel_id,
                'chapter_id' => $chapter->getKey(),
                'scene_id' => null,
                'scope_type' => 'chapter',
                'scope_id' => $chapter->getKey(),
                'stage' => GenerationStage::EventExtraction,
                'status' => RunStatus::Running,
                'attempt' => $attempt,
                'idempotency_key' => $attempt === 1 ? $baseKey : $baseKey.':attempt:'.$attempt,
                'input_hash' => $inputHash,
                'state_version' => $context['state_version'],
                'bible_version' => $chapter->novel->currentBible?->version,
                'prompt_version' => $promptVersion,
                'model_policy' => $model,
                'context_snapshot' => [
                    ...$context,
                    'chapter_draft' => collect($context['chapter_draft'])->except('content')->all(),
                ],
                'started_at' => now(),
            ]), false];
        });
    }

    /** @return array<int, StoryEventCandidate> */
    private function validateCandidates(array $payload, Chapter $chapter, GenerationArtifact $draft): array
    {
        if (array_keys($payload) !== ['events'] || ! is_array($payload['events'])) {
            throw ValidationException::withMessages(['events' => 'Story Event Extractor 必须只返回 events 数组。']);
        }

        return collect($payload['events'])->map(function (mixed $event) use ($chapter, $draft): StoryEventCandidate {
            if (! is_array($event)) {
                throw ValidationException::withMessages(['events' => 'Story Event Candidate 必须是对象。']);
            }

            $candidate = StoryEventCandidate::fromArray($event);
            $this->validateEvidence($candidate, $chapter, $draft);
            $this->validateSubject($candidate, $chapter);

            return $candidate;
        })->all();
    }

    private function validateEvidence(StoryEventCandidate $candidate, Chapter $chapter, GenerationArtifact $draft): void
    {
        foreach ($candidate->evidence as $evidence) {
            if ($evidence['artifact_id'] !== $draft->getKey() || ! str_contains((string) $draft->content, $evidence['quote'])) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence 必须来自当前 Chapter Draft。']);
            }

            if ($evidence['scene_id'] !== null && ! $chapter->scenes->contains('id', $evidence['scene_id'])) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence 引用了其他 Chapter 的 Scene。']);
            }
        }
    }

    private function validateSubject(StoryEventCandidate $candidate, Chapter $chapter): void
    {
        if ($candidate->subjectId === null) {
            return;
        }

        $valid = match ($candidate->subjectType) {
            'character' => $chapter->novel->characters()->whereKey($candidate->subjectId)->exists(),
            'world_entity' => $chapter->novel->worldEntities()->whereKey($candidate->subjectId)->exists(),
            'foreshadowing' => $chapter->novel->foreshadowings()->whereKey($candidate->subjectId)->exists(),
            'chapter' => (string) $chapter->getKey() === $candidate->subjectId,
            default => true,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['subject_id' => 'Story Event Candidate 引用了当前 Novel 之外的实体。']);
        }
    }

    /** @param array<int, StoryEventCandidate> $candidates */
    private function complete(GenerationRun $run, Chapter $chapter, GenerationArtifact $draft, array $candidates, int $expectedStateVersion): GenerationArtifact
    {
        return DB::transaction(function () use ($run, $chapter, $draft, $candidates, $expectedStateVersion): GenerationArtifact {
            $chapter = Chapter::query()->lockForUpdate()->with('novel.canonicalStateVersion')->findOrFail($chapter->getKey());

            if ($chapter->novel->canonicalStateVersion?->version !== $expectedStateVersion) {
                throw new AiProviderException('state_version_conflict', 'Event Extraction 期间 Canonical Story State 已变化。', false);
            }

            $events = array_map(fn (StoryEventCandidate $candidate): array => $candidate->toArray(), $candidates);
            $version = GenerationArtifact::query()
                ->where('type', ArtifactType::EventCandidate)
                ->whereHas('generationRun', fn ($query) => $query->where('chapter_id', $chapter->getKey()))
                ->max('version');
            $data = [
                'status' => 'candidate',
                'source_artifact_id' => $draft->getKey(),
                'events' => $events,
            ];
            $artifact = $run->artifacts()->create([
                'type' => ArtifactType::EventCandidate,
                'version' => ((int) $version) + 1,
                'content' => json_encode($events, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'data' => $data,
                'checksum' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            ]);
            $run->update(['status' => RunStatus::Succeeded, 'finished_at' => now()]);

            return $artifact;
        });
    }

    private function failRun(GenerationRun $run, Throwable $exception): void
    {
        $run->update([
            'status' => RunStatus::Failed,
            'error_code' => $exception instanceof AiProviderException
                ? $exception->errorCode
                : ($exception instanceof ValidationException ? 'event_validation_failed' : 'event_extraction_failed'),
            'error_message' => $exception->getMessage(),
            'finished_at' => now(),
        ]);
    }
}
