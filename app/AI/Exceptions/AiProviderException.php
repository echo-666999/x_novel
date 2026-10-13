<?php

namespace App\AI\Exceptions;

use App\AI\Data\AiResponse;
use RuntimeException;
use Throwable;

class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly bool $retryable,
        public readonly ?int $statusCode = null,
        ?Throwable $previous = null,
        public readonly ?string $providerRequestId = null,
        public readonly ?AiResponse $usageResponse = null,
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
