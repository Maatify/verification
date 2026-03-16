<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Enum;

enum VerificationFailureEnum: string
{
    case INVALID_CODE = 'INVALID_CODE';
    case EXPIRED = 'EXPIRED';
    case ATTEMPTS_EXCEEDED = 'ATTEMPTS_EXCEEDED';
}
