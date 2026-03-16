<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Enum;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;

enum VerificationErrorCodeEnum: string implements ErrorCodeInterface
{
    case INVALID_CODE = 'INVALID_CODE';
    case EXPIRED_CODE = 'EXPIRED_CODE';
    case ATTEMPTS_EXCEEDED = 'ATTEMPTS_EXCEEDED';
    case GENERATION_BLOCKED = 'GENERATION_BLOCKED';
    case RATE_LIMIT = 'RATE_LIMIT';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';

    public function getValue(): string
    {
        return $this->value;
    }
}
