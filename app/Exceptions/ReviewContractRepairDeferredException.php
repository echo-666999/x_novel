<?php

namespace App\Exceptions;

use App\AI\Exceptions\AiProviderException;

/** 主审校响应已保存，下一次独立执行只修复缺失的冻结合同数组。 */
final class ReviewContractRepairDeferredException extends AiProviderException
{
    /** @param array<int, string> $fields */
    public function __construct(public readonly array $fields)
    {
        parent::__construct(
            'review_contract_output_invalid',
            'Narrative Review 未按冻结合同完整返回：'.implode('、', $fields).'。原始响应已保存，将自动进入独立合同修复。',
            false,
        );
    }
}
