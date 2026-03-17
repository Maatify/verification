<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Application\Exception\VerificationAttemptsExceededException;
use Maatify\Verification\Application\Exception\VerificationCodeExpiredException;
use Maatify\Verification\Application\Exception\VerificationCodeInvalidException;
use Maatify\Verification\Application\Exception\VerificationGenerationBlockedException;
use Maatify\Verification\Application\Exception\VerificationInternalException;
use Maatify\Verification\Application\Exception\VerificationRateLimitException;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use RuntimeException;

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
            $this->mapGenerationException($e);
        }
    }

    public function verifyCode(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose,
        string $code
    ): void {
        try {
            $result = $this->validator->validate($identityType, $identity, $purpose, $code);
            if (!$result->success) {
                // Since the domain currently doesn't throw specific exceptions for validation failures
                // but returns them in the VerificationResult reason, we bridge it to the mapping logic
                // by wrapping it in a RuntimeException.
                throw new RuntimeException($result->reason);
            }
        } catch (RuntimeException $e) {
            $this->mapValidationException($e);
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
            $this->mapGenerationException($e);
        }
    }

    /**
     * @return never
     */
    private function mapGenerationException(RuntimeException $e): void
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'rate limit exceeded')) {
            throw new VerificationRateLimitException($e->getMessage());
        }

        if (str_contains($message, 'too many codes') || str_contains($message, 'cooldown')) {
            throw new VerificationGenerationBlockedException($e->getMessage());
        }

        throw new VerificationInternalException($e->getMessage());
    }

    /**
     * @return never
     */
    private function mapValidationException(RuntimeException $e): void
    {
        $message = strtolower($e->getMessage());

        if (str_contains($message, 'expired')) {
            throw new VerificationCodeExpiredException($e->getMessage());
        }

        if (str_contains($message, 'attempts')) {
            throw new VerificationAttemptsExceededException($e->getMessage());
        }

        if (str_contains($message, 'invalid')) {
            throw new VerificationCodeInvalidException($e->getMessage());
        }

        throw new VerificationInternalException($e->getMessage());
    }
}
