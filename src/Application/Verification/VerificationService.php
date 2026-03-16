<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

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
        $generated = $this->generator->generate($identityType, $identity, $purpose);

        return $generated->plainCode;
    }

    public function verifyCode(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose,
        string $code
    ): bool {
        $result = $this->validator->validate($identityType, $identity, $purpose, $code);

        return $result->success;
    }

    public function resendVerification(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose
    ): string {
        $generated = $this->generator->generate($identityType, $identity, $purpose);

        return $generated->plainCode;
    }
}
