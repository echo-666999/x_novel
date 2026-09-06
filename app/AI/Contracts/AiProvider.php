<?php

namespace App\AI\Contracts;

use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;

interface AiProvider
{
    public function generate(AiRequest $request): AiResponse;
}
