<?php

namespace App\Data;

use App\Enums\EventType;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class StoryEventCandidate
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, array<string, mixed>>  $evidence
     */
    public function __construct(
        public EventType $eventType,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $payload,
        public array $evidence,
        public ?string $storyTime,
        public float $confidence,
    ) {}

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['event_type', 'subject_type', 'subject_id', 'payload', 'evidence', 'story_time', 'confidence'],
            'properties' => [
                'event_type' => ['type' => 'string', 'enum' => array_column(EventType::cases(), 'value')],
                'subject_type' => ['type' => ['string', 'null']],
                'subject_id' => ['type' => ['string', 'integer', 'null']],
                'payload' => ['type' => 'object'],
                'evidence' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['artifact_id', 'scene_id', 'quote', 'start_offset', 'end_offset'],
                        'properties' => [
                            'artifact_id' => ['type' => 'integer'],
                            'scene_id' => ['type' => ['integer', 'null']],
                            'quote' => ['type' => 'string'],
                            'start_offset' => ['type' => ['integer', 'null']],
                            'end_offset' => ['type' => ['integer', 'null']],
                        ],
                    ],
                ],
                'story_time' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
            ],
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $allowed = array_keys(self::schema()['properties']);

        if (array_diff(array_keys($data), $allowed) !== []) {
            throw ValidationException::withMessages(['event' => 'Story Event Candidate 包含未声明字段。']);
        }

        $allowedEvidence = array_keys(self::schema()['properties']['evidence']['items']['properties']);

        foreach ($data['evidence'] ?? [] as $evidence) {
            if (! is_array($evidence) || array_diff(array_keys($evidence), $allowedEvidence) !== []) {
                throw ValidationException::withMessages(['evidence' => 'Candidate Evidence 包含未声明字段。']);
            }
        }

        $validated = Validator::make($data, [
            'event_type' => ['required', 'string', Rule::enum(EventType::class)],
            'subject_type' => ['present', 'nullable', 'string', Rule::in([
                'character', 'relationship', 'item', 'conflict', 'thread', 'reader_promise',
                'foreshadowing', 'world', 'world_entity', 'location', 'faction', 'chapter',
            ])],
            'subject_id' => ['present', 'nullable'],
            'payload' => ['present', 'array'],
            'evidence' => ['required', 'array', 'min:1'],
            'evidence.*' => ['array'],
            'evidence.*.artifact_id' => ['required', 'integer', 'min:1'],
            'evidence.*.scene_id' => ['present', 'nullable', 'integer', 'min:1'],
            'evidence.*.quote' => ['required', 'string', 'min:1'],
            'evidence.*.start_offset' => ['present', 'nullable', 'integer', 'min:0'],
            'evidence.*.end_offset' => ['present', 'nullable', 'integer', 'min:0'],
            'story_time' => ['present', 'nullable', 'string'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
        ])->validate();

        foreach ($validated['evidence'] as $evidence) {
            if ($evidence['start_offset'] !== null && $evidence['end_offset'] !== null && $evidence['end_offset'] < $evidence['start_offset']) {
                throw ValidationException::withMessages(['evidence' => 'Evidence 结束位置不能早于开始位置。']);
            }
        }

        return new self(
            eventType: EventType::from($validated['event_type']),
            subjectType: $validated['subject_type'],
            subjectId: $validated['subject_id'] === null ? null : (string) $validated['subject_id'],
            payload: $validated['payload'],
            evidence: $validated['evidence'],
            storyTime: $validated['story_time'],
            confidence: (float) $validated['confidence'],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event_type' => $this->eventType->value,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'payload' => $this->payload,
            'evidence' => $this->evidence,
            'story_time' => $this->storyTime,
            'confidence' => $this->confidence,
        ];
    }
}
