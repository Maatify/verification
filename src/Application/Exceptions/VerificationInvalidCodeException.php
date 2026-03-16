<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;

class VerificationInvalidCodeException extends VerificationException
{
    protected string $errorCode = 'VERIFICATION_INVALID_CODE';
    protected string $messageKey = 'verification.invalid_code';
    protected int $statusCode = 422;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::VALIDATION;
}
