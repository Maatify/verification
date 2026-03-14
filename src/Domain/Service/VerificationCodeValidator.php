<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Service;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\DTO\VerificationResult;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

readonly class VerificationCodeValidator implements VerificationCodeValidatorInterface
{
    public function __construct(
        private VerificationCodeRepositoryInterface $repository,
        private string $secret
    ) {
    }

    public function validate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, string $plainCode, ?string $usedIp = null): VerificationResult
    {
        $inputHash = hash_hmac('sha256', $plainCode, $this->secret);

        // Atomic Database-Enforced Validation
        // This query evaluates status, expiry, attempts, and hash in a single atomic operation.
        $success = $this->repository->markUsed(
            $identityType,
            $identityId,
            $purpose,
            $inputHash,
            $usedIp
        );

        if (!$success) {
            // If validation failed (wrong guess, expired, or locked out), increment attempts
            // strictly on the latest active challenge for this identity scope.
            $this->repository->incrementAttempts($identityType, $identityId, $purpose);
            return VerificationResult::failure('Invalid code.');
        }

        // Revoke all other active codes for this scope upon success
        // Since we don't fetch the code ID into memory before marking used anymore,
        // we can just revoke all active codes for this purpose (since the successful one is now 'used').
        $this->repository->revokeAllFor(
            $identityType,
            $identityId,
            $purpose
        );

        return VerificationResult::success($identityType, $identityId, $purpose);
    }
}
