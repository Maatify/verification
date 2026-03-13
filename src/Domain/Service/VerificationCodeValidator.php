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
        $code = $this->repository->findActive($identityType, $identityId, $purpose);
        $inputHash = hash('sha256', $plainCode);
        $dummyHash = hash('sha256', '000000');

        if ($code === null) {
            // Constant-time dummy check to prevent timing attacks
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if ($code->expiresAt < $this->clock->now()) {
            $this->repository->expire($code->id);
            // Dummy check
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if ($code->attempts >= $code->maxAttempts) {
            // Dummy check
            $_ = hash_equals($dummyHash, $inputHash);
            return VerificationResult::failure('Invalid code.');
        }

        if (!hash_equals($code->codeHash, $inputHash)) {
            $this->repository->incrementAttempts($code->id);
            return VerificationResult::failure('Invalid code.');
        }

        // Replay Protection: relies on the atomic SQL update in markUsed()
        if (!$this->repository->markUsed($code->id, $usedIp)) {
            return VerificationResult::failure('Invalid code.');
        }

        $this->repository->revokeAllFor($identityType, $identityId, $purpose);

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }

    public function validateByCode(string $plainCode, ?string $usedIp = null): VerificationResult
    {
        $codeHash = hash('sha256', $plainCode);
        $dummyHash = hash('sha256', '000000');

        $code = $this->repository->findByCodeHash($codeHash);

        if ($code === null) {
            $_ = hash_equals($dummyHash, $codeHash);
            return VerificationResult::failure('Invalid code.');
        }

        // Do not check in-memory status array! Only rely on DB update.
        if ($code->expiresAt < $this->clock->now()) {
            $this->repository->expire($code->id);
            $_ = hash_equals($dummyHash, $codeHash);
            return VerificationResult::failure('Invalid code.');
        }

        if ($code->attempts >= $code->maxAttempts) {
            $_ = hash_equals($dummyHash, $codeHash);
            return VerificationResult::failure('Invalid code.');
        }

        if (!hash_equals($code->codeHash, $codeHash)) {
            $this->repository->incrementAttempts($code->id);
            return VerificationResult::failure('Invalid code.');
        }

        // Replay Protection: relies on the atomic SQL update in markUsed()
        if (!$this->repository->markUsed($code->id, $usedIp)) {
            return VerificationResult::failure('Invalid code.');
        }

        $this->repository->revokeAllFor($code->identityType, $code->identityId, $code->purpose);

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }
}
