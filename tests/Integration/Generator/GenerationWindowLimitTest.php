<?php

declare(strict_types=1);

namespace Tests\Integration\Generator;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use Maatify\Verification\Domain\Exception\VerificationGenerationRateLimitedException;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class GenerationWindowLimitTest extends DatabaseTestCase
{
    public function testExceedingMaxCodesPerWindowThrowsException(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);
        $policyResolver = new VerificationCodePolicyResolver();
        $generator = new VerificationCodeGenerator(
            $repository,
            $policyResolver,
            $clock,
            new PdoTransactionManager($this->getPdo()),
            'test_secret'
        );

        $policy = $policyResolver->resolve(VerificationPurposeEnum::EmailVerification);

        // Fast forward clock for each generation to bypass resend cooldown
        for ($i = 0; $i < $policy->maxCodesPerWindow; $i++) {
            $result = $generator->generate(IdentityTypeEnum::User, 'user3', VerificationPurposeEnum::EmailVerification);

            // Mark as used so it doesn't get revoked by the maxActiveCodes limit
            $repository->markUsed(IdentityTypeEnum::User, 'user3', VerificationPurposeEnum::EmailVerification, hash_hmac('sha256', $result->plainCode, 'test_secret'));

            $clock->modify('+2 minutes');
        }

        $this->expectException(VerificationGenerationRateLimitedException::class);
        $this->expectExceptionMessage('Too many codes generated in the current window.');

        $generator->generate(IdentityTypeEnum::User, 'user3', VerificationPurposeEnum::EmailVerification);
    }
}
