<?php

namespace App\Actions\Story;

use App\Enums\FactStatus;
use App\Models\Fact;
use App\Models\Novel;
use Illuminate\Support\Facades\DB;

class SupersedeFactAction
{
    public function execute(Novel $novel, Fact $fact): Fact
    {
        return DB::transaction(function () use ($novel, $fact): Fact {
            $lockedFact = Fact::query()
                ->whereBelongsTo($novel)
                ->lockForUpdate()
                ->findOrFail($fact->getKey());

            if ($lockedFact->status === FactStatus::Active) {
                $lockedFact->update(['status' => FactStatus::Superseded]);
            }

            return $lockedFact;
        });
    }
}
