<?php

namespace App\Services;

use App\Actions\Novels\CreateBibleVersionAction;
use App\Enums\BibleStatus;
use App\Models\Novel;
use App\Models\NovelBible;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class NarrativeStyleProfile
{
    public function __construct(private readonly CreateBibleVersionAction $createBibleVersion) {}

    /** @return array<string, mixed> */
    public function forNovel(Novel $novel): array
    {
        $bible = $novel->currentBible()->first();

        if ($bible === null) {
            throw ValidationException::withMessages([
                'current_bible' => '缺少 Current Bible。',
            ]);
        }

        if ($bible->status !== BibleStatus::Current) {
            throw ValidationException::withMessages([
                'current_bible.status' => '最新小说圣经不是 Current 状态。',
            ]);
        }

        return $this->forBible($bible);
    }

    /** @return array<string, mixed> */
    public function forBible(NovelBible $bible): array
    {
        return $this->profileFromContract($this->contractForBible($bible));
    }

    /** @param array<string, mixed> $contract @return array<string, mixed> */
    public function profileFromContract(array $contract): array
    {
        $secondaryInstructions = collect($contract['secondary_styles'])
            ->map(fn (array $style): string => "辅助文风（{$style['name']}）：{$style['instruction']}")
            ->all();

        return [
            'subgenre' => data_get($contract, 'positioning.subgenre.value'),
            'target_platform' => data_get($contract, 'positioning.target_platform.label'),
            'story_tone' => $contract['tone'],
            'primary_style' => data_get($contract, 'primary_style.name'),
            'secondary_styles' => collect($contract['secondary_styles'])->pluck('name')->all(),
            'language_era' => data_get($contract, 'language_era.label'),
            'pacing' => data_get($contract, 'pacing.label'),
            'narrative_pov' => $contract['pov'],
            'tense' => $contract['tense'],
            'parameters' => $contract['parameters'],
            'instructions' => [
                data_get($contract, 'primary_style.instruction'),
                ...$secondaryInstructions,
            ],
        ];
    }

    /** @return array<string, mixed> */
    public function contractForBible(NovelBible $bible): array
    {
        $profile = $this->validatedProfile($bible);
        $styles = config('narrative.styles', []);
        $primaryCode = $profile['primary_style'];
        $secondaryCodes = $profile['secondary_styles'];
        $contract = [
            'schema_version' => 1,
            'bible_id' => $bible->getKey(),
            'bible_version' => $bible->version,
            'tone' => $bible->tone,
            'pov' => $bible->pov,
            'tense' => $bible->tense,
            'positioning' => [
                'logline' => $bible->logline,
                'themes' => $bible->themes ?? [],
                'subgenre' => [
                    'value' => $profile['subgenre'],
                    'label' => $this->label('subgenres', $profile['subgenre']),
                ],
                'target_platform' => [
                    'code' => $profile['target_platform'],
                    'label' => $this->label('platforms', $profile['target_platform']),
                ],
            ],
            'primary_style' => [
                'code' => $primaryCode,
                'name' => $styles[$primaryCode]['name'],
                'instruction' => $styles[$primaryCode]['instruction'],
            ],
            'secondary_styles' => collect($secondaryCodes)->map(fn (string $code): array => [
                'code' => $code,
                'name' => $styles[$code]['name'],
                'instruction' => $styles[$code]['instruction'],
            ])->all(),
            'language_era' => [
                'code' => $profile['language_era'],
                'label' => $this->label('language_eras', $profile['language_era']),
            ],
            'pacing' => [
                'code' => $profile['pacing'],
                'label' => $this->label('paces', $profile['pacing']),
            ],
            'parameters' => $profile['parameters'],
            'expanded_parameters' => $this->expandedParameters($profile['parameters']),
            'constraints' => [
                'taboos' => $bible->taboos ?? [],
                'hard_constraints' => $bible->hard_constraints ?? [],
            ],
        ];

        return [
            ...$contract,
            'checksum' => $this->checksum($contract),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedProfile(NovelBible $bible): array
    {
        Validator::make([
            'tone' => $bible->tone,
            'pov' => $bible->pov,
            'tense' => $bible->tense,
        ], [
            'tone' => ['required', 'string'],
            'pov' => ['required', 'string'],
            'tense' => ['required', 'string'],
        ], [
            'tone.required' => 'Current Bible 缺少基调。',
            'pov.required' => 'Current Bible 缺少视角。',
            'tense.required' => 'Current Bible 缺少时态。',
        ])->validate();

        return $this->createBibleVersion->validateStyleProfile($bible->style_profile);
    }

    private function label(string $group, mixed $code): string
    {
        return (string) data_get(config("narrative.{$group}", []), (string) $code, $code);
    }

    /** @param array<string, int> $parameters @return array<string, array{value: int, level: string, instruction: string}> */
    private function expandedParameters(array $parameters): array
    {
        $levels = [1 => '很低', 2 => '较低', 3 => '适中', 4 => '较高', 5 => '很高'];

        return collect($parameters)->mapWithKeys(fn (int $value, string $key): array => [
            $key => [
                'value' => $value,
                'level' => $levels[$value],
                'instruction' => $this->parameterInstruction($key, $value),
            ],
        ])->all();
    }

    private function parameterInstruction(string $key, int $value): string
    {
        return match ($key) {
            'ornateness' => match ($value) {
                1 => '使用朴素直接的词句，基本不使用装饰性修辞。',
                2 => '以直接表达为主，只在关键画面使用少量修辞。',
                3 => '直接叙述与修辞保持平衡，避免连续堆叠意象。',
                4 => '适度增加意象和修辞，但必须保持句意清晰。',
                5 => '使用丰富意象和精细修辞，同时避免晦涩与辞藻堆砌。',
            },
            'dialogue_ratio' => match ($value) {
                1 => '对白从简，以叙述和行动承载主要信息。',
                2 => '少量使用对白，只保留能推动人物或情节的交流。',
                3 => '对白、行动与叙述保持均衡。',
                4 => '较多使用自然对白推动冲突和人物关系。',
                5 => '以高密度对白推进场景，但不得写成脱离动作与环境的对话稿。',
            },
            'description_density' => match ($value) {
                1 => '环境描写只保留理解动作所需的最少信息。',
                2 => '使用简短环境细节定位场景，不展开无关景物。',
                3 => '环境、动作和对白保持均衡。',
                4 => '用较充分的环境与感官细节强化氛围和行动。',
                5 => '使用高密度环境与感官描写，但每处细节都必须服务氛围或剧情。',
            },
            'psychology_density' => match ($value) {
                1 => '主要通过动作和对白表现心理，避免直接解释。',
                2 => '只在关键转折补充简短心理反应。',
                3 => '内心活动与外部行动保持均衡。',
                4 => '充分呈现动机、犹豫和情绪变化，并与行动因果相连。',
                5 => '深入展开心理层次和变化过程，但不得用反复独白拖慢情节。',
            },
            'humor_level' => match ($value) {
                1 => '保持严肃克制，不主动制造笑点。',
                2 => '仅在自然互动中使用少量轻松表达。',
                3 => '适度使用人物反差或情境幽默，不削弱冲突。',
                4 => '较频繁使用对白和情境幽默，严肃节点主动收敛。',
                5 => '维持高密度喜剧效果，但笑点必须服务人物和剧情。',
            },
            'literary_level' => match ($value) {
                1 => '优先清晰、顺畅和情节推进，避免文学化表达。',
                2 => '保持通俗可读，只在关键段落增加表达质感。',
                3 => '可读性与语言质感保持均衡。',
                4 => '重视节奏、意象和句式变化，同时保持网文可读性。',
                5 => '追求精细语言、意象和结构呼应，但不得牺牲清晰度。',
            },
        };
    }

    /** @param array<string, mixed> $contract */
    private function checksum(array $contract): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($contract),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
