<?php

namespace App\Exceptions;

use App\AI\Exceptions\AiProviderException;

/** 主审校响应已持久化；下一次独立执行只校正正文引用。 */
final class ReviewEvidenceRepairDeferredException extends AiProviderException
{
    public function __construct(int $count)
    {
        parent::__construct(
            'review_evidence_output_invalid',
            "Narrative Review 有 {$count} 项证据未逐字命中正文；原始响应已保存，将自动进入独立证据修复。",
            false,
        );
    }
}
