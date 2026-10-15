<?php

namespace App\Exceptions;

use Illuminate\Validation\ValidationException;

/**
 * 标识全书字数、分卷或主线章节预算之间的冻结合同不成立。
 *
 * 独立异常类型让 Run 能记录稳定错误码，并与其他 Outline 领域校验失败区分。
 */
final class OutlineLengthContractException extends ValidationException
{
    public const ERROR_CODE = 'outline_length_contract_invalid';
}
