<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Application\Exception\VerificationGenerationBlockedException;
use Maatify\Verification\Application\Exception\VerificationInternalException;
use Maatify\Verification\Application\Exception\VerificationInvalidCodeException;
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
        $result = $this->validator->validate($identityType, $identity, $purpose, $code);

        if (!$result->success) {
            throw new VerificationInvalidCodeException('Invalid verification code.');
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
        $message = $e->getMessage();

        // TODO: Replace message-based mapping with typed domain exceptions in future versions
        if ($message === 'Too many codes generated in the current window.') {
            throw new VerificationRateLimitException($message);
        }

        if ($message === 'Please wait before requesting a new code.') {
            throw new VerificationGenerationBlockedException($message);
        }

        throw new VerificationInternalException($message);
    }
}
