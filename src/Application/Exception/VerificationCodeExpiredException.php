<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exception;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Verification\Application\Enum\VerificationErrorCodeEnum;

class VerificationCodeExpiredException extends VerificationException
{
    public function __construct(string $message = 'Verification code has expired.')
    {
        parent::__construct($message);
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return VerificationErrorCodeEnum::EXPIRED_CODE;
    }

    protected function defaultCategory(): ErrorCategoryInterface
    {
        return ErrorCategoryEnum::VALIDATION;
    }

    protected function defaultHttpStatus(): int
    {
        return 400;
    }
}
