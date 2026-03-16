<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Exceptions;

use Maatify\Exceptions\Contracts\ErrorCategoryInterface;
use Maatify\Exceptions\Contracts\ErrorCodeInterface;
use Maatify\Exceptions\Contracts\ErrorPolicyInterface;
use Maatify\Exceptions\Contracts\EscalationPolicyInterface;
use Maatify\Exceptions\Enum\ErrorCategoryEnum;
use Maatify\Exceptions\Exception\MaatifyException;
use Throwable;

abstract class VerificationException extends MaatifyException
{
    protected string $errorCode = 'VERIFICATION_INTERNAL';
    protected string $messageKey = 'verification.internal_error';
    protected int $statusCode = 500;
    protected ErrorCategoryInterface $category = ErrorCategoryEnum::SYSTEM;

    public function __construct(
        string $message = '',
        int $code = 0,
        ?Throwable $previous = null,
        ?ErrorCodeInterface $errorCodeOverride = null,
        ?int $httpStatusOverride = null,
        ?bool $isSafeOverride = null,
        ?bool $isRetryableOverride = null,
        array $meta = [],
        ?ErrorPolicyInterface $policy = null,
        ?EscalationPolicyInterface $escalationPolicy = null
    ) {
        if ($message === '') {
            $message = $this->messageKey;
        }

        parent::__construct(
            $message,
            $code,
            $previous,
            $errorCodeOverride,
            $httpStatusOverride,
            $isSafeOverride,
            $isRetryableOverride,
            $meta,
            $policy,
            $escalationPolicy
        );
    }

    protected function defaultErrorCode(): ErrorCodeInterface
    {
        return VerificationErrorCodeEnum::from($this->errorCode);
    }

    protected function defaultCategory(): ErrorCategoryInterface
    {
        return $this->category;
    }

    protected function defaultHttpStatus(): int
    {
        return $this->statusCode;
    }
}
