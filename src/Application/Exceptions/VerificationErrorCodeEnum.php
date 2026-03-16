<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCodeInterface;

enum VerificationErrorCodeEnum: string implements ErrorCodeInterface
{
    case VERIFICATION_INVALID_CODE = 'VERIFICATION_INVALID_CODE';
    case VERIFICATION_EXPIRED = 'VERIFICATION_EXPIRED';
    case VERIFICATION_ATTEMPTS_EXCEEDED = 'VERIFICATION_ATTEMPTS_EXCEEDED';
    case VERIFICATION_GENERATION_BLOCKED = 'VERIFICATION_GENERATION_BLOCKED';
    case VERIFICATION_INTERNAL = 'VERIFICATION_INTERNAL';

    public function getValue(): string
    {
        return $this->value;
    }
}
