<?php

namespace App\Enums;

/** Artifact 只有经过当前阶段确定性校验后，才允许进入后续来源链。 */
enum ArtifactValidationStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Rejected = 'rejected';
}
