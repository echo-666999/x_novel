<?php

namespace App\Services;

use App\Models\NovelOutlineBeat;

/**
 * 为相邻 Main Beat 构造唯一、稳定的 Handoff 契约。
 */
final class OutlineHandoffContract
{
    /** @return array<string, mixed> */
    public function forBeat(NovelOutlineBeat $beat): array
    {
        $beat->loadMissing('handoffNextBeat');

        return [
            'next_beat_id' => $beat->handoff_next_beat_id,
            'next_beat_key' => $beat->handoffNextBeat?->beat_key,
            'transition_mode' => $beat->handoff_transition_mode,
            'exit_result' => $beat->handoff_exit_result,
            'next_trigger' => $beat->handoff_next_trigger,
            'carried_states' => array_values($beat->handoff_carried_states ?? []),
            'open_threads' => array_values($beat->handoff_open_threads ?? []),
            'required_transition' => array_values($beat->handoff_required_transition ?? []),
            'forbidden_jump' => array_values($beat->handoff_forbidden_jump ?? []),
        ];
    }

    /** @param array<string, mixed> $contract */
    public function checksum(array $contract): string
    {
        return hash('sha256', json_encode($contract, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
