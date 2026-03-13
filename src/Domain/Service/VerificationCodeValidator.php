<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Service;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\DTO\VerificationResult;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

readonly class VerificationCodeValidator implements VerificationCodeValidatorInterface
{
    public function __construct(
        private VerificationCodeRepositoryInterface $repository,
        private ClockInterface $clock
    ) {
    }

    public function validate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, string $plainCode, ?string $usedIp = null): VerificationResult
    {
        // 1. Find active code
        $code = $this->repository->findActive($identityType, $identityId, $purpose);
        $inputHash = hash('sha256', $plainCode);
        $dummyHash = hash('sha256', '000000');

        $isValid = true;

        if ($code === null) {
            $isValid = false;
        }

        // 2. Check expiry
        if ($isValid && $code !== null && $code->expiresAt < $this->clock->now()) {
            $this->repository->expire($code->id);
            $isValid = false;
        }

        // 3. Check attempts
        if ($isValid && $code !== null && $code->attempts >= $code->maxAttempts) {
            $this->repository->expire($code->id);
            $isValid = false;
        }

        // 4. Constant-time comparison
        $hashToCompare = ($isValid && $code !== null) ? $code->codeHash : $dummyHash;
        $hashMatches = hash_equals($hashToCompare, $inputHash);

        if (!$isValid || !$hashMatches) {
            if ($isValid && $code !== null) {
                // Increment attempts on failure ONLY when code is active and valid, but hash is incorrect
                $this->repository->incrementAttempts($code->id);
                // Check if this attempt exceeded max
                if ($code->attempts + 1 >= $code->maxAttempts) {
                    $this->repository->expire($code->id);
                }
            }
            return VerificationResult::failure('Invalid code.');
        }

        // 5. Mark used on success
        /** @var \Maatify\Verification\Domain\DTO\VerificationCode $code */
        $success = $this->repository->markUsed($code->id, $usedIp);
        if (!$success) {
            return VerificationResult::failure('Invalid code.');
        }

        // 6. Revoke other active codes for this identity
        $this->repository->revokeAllFor($identityType, $identityId, $purpose);

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }

    public function validateByCode(string $plainCode, ?string $usedIp = null): VerificationResult
    {
        // 1. Hash the input
        $codeHash = hash('sha256', $plainCode);
        $dummyHash = hash('sha256', '000000');

        // 2. Lookup by hash
        $code = $this->repository->findByCodeHash($codeHash);

        $isValid = true;

        if ($code === null) {
            // No matching code found (or hash mismatch implies not found)
            $isValid = false;
        }

        // 3. Check status
        if ($isValid && $code !== null && in_array($code->status, [
            VerificationCodeStatus::USED,
            VerificationCodeStatus::REVOKED,
            VerificationCodeStatus::EXPIRED,
        ], true)) {
            $isValid = false;
        }

        if ($isValid && $code !== null && $code->status !== VerificationCodeStatus::ACTIVE) {
            $isValid = false;
        }

        // 4. Check expiry
        if ($isValid && $code !== null && $code->expiresAt < $this->clock->now()) {
            $this->repository->expire($code->id);
            $isValid = false;
        }

        // 5. Check attempts
        // Even if hash matches, maybe it was locked out previously?
        if ($isValid && $code !== null && $code->attempts >= $code->maxAttempts) {
            $this->repository->incrementAttempts($code->id);
            $this->repository->expire($code->id);
            $isValid = false;
        }

        $hashToCompare = ($isValid && $code !== null) ? $code->codeHash : $dummyHash;
        $hashMatches = hash_equals($hashToCompare, $codeHash);

        if (!$isValid || !$hashMatches) {
            if ($isValid && $code !== null) {
                // Increment attempts on failure ONLY when code is active and valid, but hash is incorrect
                $this->repository->incrementAttempts($code->id);
                // Check if this attempt exceeded max
                if ($code->attempts + 1 >= $code->maxAttempts) {
                    $this->repository->expire($code->id);
                }
            }
            return VerificationResult::failure('Invalid code.');
        }

        // 6. Success -> Mark used
        /** @var \Maatify\Verification\Domain\DTO\VerificationCode $code */
        $success = $this->repository->markUsed($code->id, $usedIp);
        if (!$success) {
            return VerificationResult::failure('Invalid code.');
        }

        // 7. Revoke other active codes for this identity
        $this->repository->revokeAllFor($code->identityType, $code->identityId, $code->purpose);

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }
}
