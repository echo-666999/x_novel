<?php

namespace App\Services;

class SceneExecutionClock
{
    public function start(): int
    {
        return hrtime(true);
    }

    public function elapsedSeconds(int $startedAt): int
    {
        return (int) floor((hrtime(true) - $startedAt) / 1_000_000_000);
    }
}
