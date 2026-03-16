<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;

class VerificationAttemptsExceededException extends VerificationException
{
    protected string $errorCode = 'VERIFICATION_ATTEMPTS_EXCEEDED';
    protected string $messageKey = 'verification.attempts_exceeded';
    protected int $statusCode = 429;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::RATE_LIMIT;
}
