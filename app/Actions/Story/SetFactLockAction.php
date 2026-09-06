<?php

namespace App\Actions\Story;

use App\Enums\FactStatus;
use App\Models\Fact;
use App\Models\Novel;
use DomainException;
use Illuminate\Support\Facades\DB;

class SetFactLockAction
{
    public function execute(Novel $novel, Fact $fact, bool $locked): Fact
    {
        return DB::transaction(function () use ($novel, $fact, $locked): Fact {
            $lockedFact = Fact::query()
                ->whereBelongsTo($novel)
                ->lockForUpdate()
                ->findOrFail($fact->getKey());

            if ($lockedFact->status !== FactStatus::Active) {
                throw new DomainException('Only active facts can change lock state.');
            }

            if ($lockedFact->locked !== $locked) {
                $lockedFact->update(['locked' => $locked]);
            }

            return $lockedFact;
        });
    }
}
