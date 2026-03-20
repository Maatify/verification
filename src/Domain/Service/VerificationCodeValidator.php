<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Service;

use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\DTO\VerificationResult;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Exception\InvalidVerificationCodeException;

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
        // It now returns a VerificationUseResult with an explicit status enum.
        $useResult = $this->repository->markUsed(
            $identityType,
            $identityId,
            $purpose,
            $inputHash,
            $usedIp
        );

        if ($useResult->status !== \Maatify\Verification\Domain\Enum\VerificationUseStatus::SUCCESS) {
            // If validation failed (wrong guess, expired, or locked out), increment attempts
            // strictly on the latest active challenge for this identity scope.
            $this->repository->incrementAttempts($identityType, $identityId, $purpose);

            throw match ($useResult->status) {
                \Maatify\Verification\Domain\Enum\VerificationUseStatus::EXPIRED => new \Maatify\Verification\Domain\Exception\VerificationCodeExpiredException('Verification code has expired.'),
                \Maatify\Verification\Domain\Enum\VerificationUseStatus::ATTEMPTS_EXCEEDED => new \Maatify\Verification\Domain\Exception\VerificationAttemptsExceededException('Maximum attempts exceeded.'),
                default => new InvalidVerificationCodeException('Invalid code.'),
            };
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
