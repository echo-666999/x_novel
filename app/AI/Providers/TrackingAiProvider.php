<?php

namespace App\AI\Providers;

use App\AI\Contracts\AiProvider;
use App\AI\Data\AiRequest;
use App\AI\Data\AiResponse;
use App\AI\Exceptions\AiProviderException;
use App\AI\RequestUsageLedger;
use App\AI\UsageRecorder;
use Throwable;

/** 生成、诊断和评测共用请求账本；失败响应的真实用量同样结算。 */
class TrackingAiProvider implements AiProvider
{
    public function __construct(private readonly AiProvider $provider, private readonly UsageRecorder $usageRecorder) {}

    public function generate(AiRequest $request): AiResponse
    {
        $ledger = app(RequestUsageLedger::class);
        $entry = $ledger->prepare($request);
        $sent = false;
        try {
            $ledger->send($entry);
            $sent = true;
            $response = $this->provider->generate($request);
            $this->usageRecorder->record($request, $response, $entry);

            return $response;
        } catch (Throwable $exception) {
            if ($exception instanceof AiProviderException && $exception->usageResponse !== null) {
                $this->usageRecorder->record($request, $exception->usageResponse, $entry);
            } else {
                $ledger->failed($entry, ! $sent || ($exception instanceof AiProviderException && in_array($exception->errorCode, ['provider_not_configured', 'provider_disabled', 'provider_unsupported'], true)));
            }
            throw $exception;
        }
    }
}
