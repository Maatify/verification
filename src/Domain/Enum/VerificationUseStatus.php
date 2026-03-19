<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Enum;

enum VerificationUseStatus: string
{
    case SUCCESS = 'success';
    case INVALID_CODE = 'invalid_code';
    case EXPIRED = 'expired';
    case ATTEMPTS_EXCEEDED = 'attempts_exceeded';
}
