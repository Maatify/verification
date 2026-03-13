<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Contracts;

use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

interface VerificationCodeRepositoryInterface
{
    public function store(VerificationCode $code): void;

    public function findActive(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): ?VerificationCode;

    public function findByCodeHash(string $codeHash): ?VerificationCode;

    public function incrementAttempts(int $codeId): void;

    public function markUsed(int $codeId, ?string $usedIp = null): bool;

    public function expire(int $codeId): void;

    public function expireAllFor(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): void;

    /**
     * @param array<int> $exceptIds
     */
    public function revokeAllFor(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, array $exceptIds = []): void;

    /**
     * @return VerificationCode[]
     */
    public function findAllActive(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose
    ): array;

    public function countActiveInWindow(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, \DateTimeInterface $since): int;

    /**
    * Locks active codes for update.
    *
    * Used to guarantee atomic generation.
    *
    * @return VerificationCode[]
    */
    public function lockActiveForUpdate(
        IdentityTypeEnum $identityType,
        string $identityId,
        VerificationPurposeEnum $purpose
    ): array;
}
