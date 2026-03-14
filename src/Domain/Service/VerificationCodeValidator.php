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
    private const DUMMY_HASH = '91b4d142823f9a29f4d1b0b90a9f1dbe64c5b7c0e92ce409dab66f6cc0b93e63';
    public function __construct(
        private VerificationCodeRepositoryInterface $repository,
        private ClockInterface $clock,
        private string $secret
    ) {
    }

    public function validate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, string $plainCode, ?string $usedIp = null): VerificationResult
    {
        $code = $this->repository->findActive($identityType, $identityId, $purpose);
        $inputHash = hash_hmac('sha256', $plainCode, $this->secret);
        $dummyHash = self::DUMMY_HASH;

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

        $this->repository->revokeAllFor(
            $identityType,
            $identityId,
            $purpose,
            [$code->id]
        );

        return VerificationResult::success($code->identityType, $code->identityId, $code->purpose);
    }
}
