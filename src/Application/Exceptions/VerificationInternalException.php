<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;

class VerificationInternalException extends VerificationException
{
    protected string $errorCode = 'VERIFICATION_INTERNAL';
    protected string $messageKey = 'verification.internal_error';
    protected int $statusCode = 500;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::SYSTEM;
}
