<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;

class VerificationGenerationBlockedException extends VerificationException
{
    protected string $errorCode = 'VERIFICATION_GENERATION_BLOCKED';
    protected string $messageKey = 'verification.generation_blocked';
    protected int $statusCode = 429;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::RATE_LIMIT;
}
