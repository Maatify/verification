<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exception;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Verification\Application\Enum\VerificationErrorCodeEnum;

class VerificationRateLimitException extends VerificationException
{
    public function __construct(string $message = 'Too many requests. Please try again later.')
    {
        parent::__construct($message);
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return VerificationErrorCodeEnum::RATE_LIMIT;
    }

    protected function defaultCategory(): ErrorCategoryInterface
    {
        return ErrorCategoryEnum::RATE_LIMIT;
    }

    protected function defaultHttpStatus(): int
    {
        return 429;
    }
}
