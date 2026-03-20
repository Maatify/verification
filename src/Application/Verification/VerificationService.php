<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Application\Exception\VerificationAttemptsExceededException;
use Maatify\Verification\Application\Exception\VerificationCodeExpiredException;
use Maatify\Verification\Application\Exception\VerificationInvalidCodeException;
use Maatify\Verification\Application\Exception\VerificationGenerationBlockedException;
use Maatify\Verification\Application\Exception\VerificationInternalException;
use Maatify\Verification\Application\Exception\VerificationRateLimitException;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Exception\InvalidVerificationCodeException;
use Maatify\Verification\Domain\Exception\VerificationAttemptsExceededException as DomainAttemptsExceededException;
use Maatify\Verification\Domain\Exception\VerificationCodeExpiredException as DomainCodeExpiredException;
use Maatify\Verification\Domain\Exception\VerificationDomainException;
use Maatify\Verification\Domain\Exception\VerificationGenerationBlockedException as DomainGenerationBlockedException;
use Maatify\Verification\Domain\Exception\VerificationRateLimitExceededException as DomainRateLimitExceededException;

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
        } catch (DomainRateLimitExceededException $e) {
            throw new VerificationRateLimitException($e->getMessage());
        } catch (DomainGenerationBlockedException $e) {
            throw new VerificationGenerationBlockedException($e->getMessage());
        } catch (VerificationDomainException $e) {
            throw new VerificationInternalException($e->getMessage());
        }
    }

    public function verifyCode(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose,
        string $code
    ): void {
        try {
            $this->validator->validate($identityType, $identity, $purpose, $code);
        } catch (DomainCodeExpiredException $e) {
            throw new VerificationCodeExpiredException($e->getMessage());
        } catch (DomainAttemptsExceededException $e) {
            throw new VerificationAttemptsExceededException($e->getMessage());
        } catch (InvalidVerificationCodeException $e) {
            throw new VerificationInvalidCodeException($e->getMessage());
        } catch (VerificationDomainException $e) {
            throw new VerificationInternalException($e->getMessage());
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
        } catch (DomainRateLimitExceededException $e) {
            throw new VerificationRateLimitException($e->getMessage());
        } catch (DomainGenerationBlockedException $e) {
            throw new VerificationGenerationBlockedException($e->getMessage());
        } catch (VerificationDomainException $e) {
            throw new VerificationInternalException($e->getMessage());
        }
    }
}
