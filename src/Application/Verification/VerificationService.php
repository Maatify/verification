<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Application\Exceptions\VerificationAttemptsExceededException;
use Maatify\Verification\Application\Exceptions\VerificationExpiredException;
use Maatify\Verification\Application\Exceptions\VerificationGenerationBlockedException;
use Maatify\Verification\Application\Exceptions\VerificationInternalException;
use Maatify\Verification\Application\Exceptions\VerificationInvalidCodeException;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationFailureEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Exception\VerificationGenerationRateLimitedException;
use Throwable;

readonly class VerificationService implements VerificationServiceInterface
{
    public function __construct(
        private VerificationCodeGeneratorInterface $generator,
        private VerificationCodeValidatorInterface $validator
    ) {
    }

    public function startVerification(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose
    ): string {
        try {
            $generated = $this->generator->generate($identityType, $identity, $purpose);

            return $generated->plainCode;
        } catch (VerificationGenerationRateLimitedException $e) {
            throw new VerificationGenerationBlockedException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new VerificationInternalException('An unexpected verification error occurred.', 0, $e);
        }
    }

    public function verifyCode(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose,
        string $code
    ): bool {
        try {
            $result = $this->validator->validate($identityType, $identity, $purpose, $code);

            if (! $result->success) {
                return match ($result->failureCode) {
                    VerificationFailureEnum::INVALID_CODE => throw new VerificationInvalidCodeException($result->reason),
                    VerificationFailureEnum::EXPIRED => throw new VerificationExpiredException($result->reason),
                    VerificationFailureEnum::ATTEMPTS_EXCEEDED => throw new VerificationAttemptsExceededException($result->reason),
                    default => throw new VerificationInternalException($result->reason),
                };
            }

            return true;
        } catch (VerificationInvalidCodeException | VerificationExpiredException | VerificationAttemptsExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new VerificationInternalException('An unexpected verification error occurred.', 0, $e);
        }
    }

    public function resendVerification(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose
    ): string {
        try {
            $generated = $this->generator->generate($identityType, $identity, $purpose);

            return $generated->plainCode;
        } catch (VerificationGenerationRateLimitedException $e) {
            throw new VerificationGenerationBlockedException($e->getMessage(), 0, $e);
        } catch (Throwable $e) {
            throw new VerificationInternalException('An unexpected verification error occurred.', 0, $e);
        }
    }
}
