<?php

declare(strict_types=1);

namespace Maatify\Verification\Application\Verification;

use Maatify\Verification\Application\Exception\VerificationException;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

interface VerificationServiceInterface
{
    /**
     * Starts a new verification process by generating a code.
     *
     * @param IdentityTypeEnum $identityType
     * @param string $identity
     * @param VerificationPurposeEnum $purpose
     * @return string The plain generated verification code.
     * @throws VerificationException
     */
    public function startVerification(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose
    ): string;

    /**
     * Verifies an existing code.
     *
     * @param IdentityTypeEnum $identityType
     * @param string $identity
     * @param VerificationPurposeEnum $purpose
     * @param string $code
     * @return void
     * @throws VerificationException
     */
    public function verifyCode(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose,
        string $code
    ): void;

    /**
     * Resends a verification code.
     *
     * @param IdentityTypeEnum $identityType
     * @param string $identity
     * @param VerificationPurposeEnum $purpose
     * @return string The plain generated verification code.
     * @throws VerificationException
     */
    public function resendVerification(
        IdentityTypeEnum $identityType,
        string $identity,
        VerificationPurposeEnum $purpose
    ): string;
}
