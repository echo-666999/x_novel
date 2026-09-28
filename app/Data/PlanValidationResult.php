<?php

namespace App\Data;

use App\Enums\PlanFindingSeverity;
use Illuminate\Validation\ValidationException;

readonly class PlanValidationResult
{
    /** @param array<int, PlanFinding> $findings */
    public function __construct(public array $findings) {}

    public function status(): PlanFindingSeverity
    {
        if ($this->has(PlanFindingSeverity::Blocked)) {
            return PlanFindingSeverity::Blocked;
        }

        if ($this->has(PlanFindingSeverity::Warning)) {
            return PlanFindingSeverity::Warning;
        }

        return PlanFindingSeverity::Valid;
    }

    public function canGenerate(): bool
    {
        return $this->status() !== PlanFindingSeverity::Blocked;
    }

    public function assertCanGenerate(): void
    {
        if ($this->canGenerate()) {
            return;
        }

        $messages = array_map(
            fn (PlanFinding $finding): string => implode('；', array_filter([
                "[{$finding->code}] {$finding->message}",
                $finding->field === null ? null : "字段：{$finding->field}",
                $finding->relatedRecord === null ? null : "关联记录：{$finding->relatedRecord}",
                $finding->repairAction === null ? null : "修复动作：{$finding->repairAction}",
            ])),
            array_values(array_filter(
                $this->findings,
                fn (PlanFinding $finding): bool => $finding->severity === PlanFindingSeverity::Blocked,
            )),
        );

        throw ValidationException::withMessages(['plan' => $messages]);
    }

    private function has(PlanFindingSeverity $severity): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === $severity) {
                return true;
            }
        }

        return false;
    }
}
