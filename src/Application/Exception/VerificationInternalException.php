<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exception;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Verification\Application\Enum\VerificationErrorCodeEnum;

class VerificationInternalException extends VerificationException
{
    public function __construct(string $message = 'An internal error occurred during verification.')
    {
        parent::__construct($message);
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return VerificationErrorCodeEnum::INTERNAL_ERROR;
    }

    protected function defaultCategory(): ErrorCategoryInterface
    {
        return ErrorCategoryEnum::SYSTEM;
    }

    protected function defaultHttpStatus(): int
    {
        return 500;
    }
}
