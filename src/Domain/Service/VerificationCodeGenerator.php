<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Service;

use Exception;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodePolicyResolverInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\Contracts\VerificationRateLimiterInterface;
use Maatify\Verification\Domain\DTO\GeneratedVerificationCode;
use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use RuntimeException;

readonly class VerificationCodeGenerator implements VerificationCodeGeneratorInterface
{
    public function __construct(
        private VerificationCodeRepositoryInterface $repository,
        private VerificationCodePolicyResolverInterface $policyResolver,
        private ClockInterface $clock,
        private ?VerificationRateLimiterInterface $rateLimiter = null
    ) {
    }

    public function generate(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose, ?string $createdIp = null): GeneratedVerificationCode
    {
        if ($this->rateLimiter !== null) {
            $this->rateLimiter->hit($identityType, $identityId, $purpose);
        }

        // 1. Resolve Policy
        $policy = $this->policyResolver->resolve($purpose);

        $now = $this->clock->now();

        // 2. Generation Window Limit
        $since = $now->modify("-{$policy->generationWindowMinutes} minutes");
        $countInWindow = $this->repository->countActiveInWindow($identityType, $identityId, $purpose, $since);

        if ($countInWindow >= $policy->maxCodesPerWindow) {
            throw new RuntimeException('Too many codes generated in the current window.');
        }

        // 3. Generation Cooldown & Multi-Code Window
        $activeCodes = $this->repository->findAllActive($identityType, $identityId, $purpose);

        if (!empty($activeCodes)) {
            $latestCode = $activeCodes[0];
            $secondsSinceLastCode = $now->getTimestamp() - $latestCode->createdAt->getTimestamp();

            if ($secondsSinceLastCode < $policy->resendCooldownSeconds) {
                throw new RuntimeException('Please wait before requesting a new code.');
            }
        }

        // Revoke oldest codes if we exceed max active codes limit
        // We are about to create 1, so we can only keep maxActiveCodes - 1
        $keepCount = max(0, $policy->maxActiveCodes - 1);

        $keepIds = [];
        for ($i = 0; $i < min($keepCount, count($activeCodes)); $i++) {
            $keepIds[] = $activeCodes[$i]->id;
        }

        if (count($activeCodes) > $keepCount) {
            $this->repository->revokeAllFor($identityType, $identityId, $purpose, $keepIds);
        }

        // 4. Generate random numeric OTP
        try {
            $plainCode = (string)random_int(100000, 999999);
        } catch (Exception $e) {
            throw new RuntimeException('Failed to generate secure random code.', 0, $e);
        }

        // 5. Hash
        $codeHash = hash('sha256', $plainCode);

        // 6. Create Entity
        $expiresAt = $now->modify("+{$policy->ttlSeconds} seconds");

        $entity = new VerificationCode(
            0, // ID not yet assigned
            $identityType,
            $identityId,
            $purpose,
            $codeHash,
            VerificationCodeStatus::ACTIVE,
            0,
            $policy->maxAttempts,
            $expiresAt,
            $now,
            $createdIp
        );

        // 7. Store
        $this->repository->store($entity);

        // 8. Return (Entity + Plaintext)
        return new GeneratedVerificationCode($entity, $plainCode);
    }
}
