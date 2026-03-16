<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exception;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Verification\Application\Enum\VerificationErrorCodeEnum;

class VerificationGenerationBlockedException extends VerificationException
{
    public function __construct(string $message = 'Verification code generation is temporarily blocked.')
    {
        parent::__construct($message);
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return VerificationErrorCodeEnum::GENERATION_BLOCKED;
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
