<?php

namespace App\AI\Contracts;

use App\AI\Data\EmbeddingRequest;
use App\AI\Data\EmbeddingResponse;

interface EmbeddingProvider
{
    public function embed(EmbeddingRequest $request): EmbeddingResponse;
}
