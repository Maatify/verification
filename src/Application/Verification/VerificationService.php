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
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use RuntimeException;
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
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Too many codes') || str_contains($e->getMessage(), 'wait before requesting')) {
                throw new VerificationGenerationBlockedException($e->getMessage(), 0, $e);
            }

            throw new VerificationInternalException('An internal verification error occurred.', 0, $e);
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
                if (str_contains($result->reason, 'expired')) {
                    throw new VerificationExpiredException($result->reason);
                }

                if (str_contains($result->reason, 'attempts')) {
                    throw new VerificationAttemptsExceededException($result->reason);
                }

                throw new VerificationInvalidCodeException($result->reason);
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
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'Too many codes') || str_contains($e->getMessage(), 'wait before requesting')) {
                throw new VerificationGenerationBlockedException($e->getMessage(), 0, $e);
            }

            throw new VerificationInternalException('An internal verification error occurred.', 0, $e);
        } catch (Throwable $e) {
            throw new VerificationInternalException('An unexpected verification error occurred.', 0, $e);
        }
    }
}
