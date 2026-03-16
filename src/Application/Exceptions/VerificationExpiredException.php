<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;

class VerificationExpiredException extends VerificationException
{
    protected string $errorCode = 'VERIFICATION_EXPIRED';
    protected string $messageKey = 'verification.expired';
    protected int $statusCode = 410;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::BUSINESS_RULE;
}
