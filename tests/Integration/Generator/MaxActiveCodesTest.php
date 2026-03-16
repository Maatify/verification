<?php

declare(strict_types=1);

namespace Tests\Integration\Generator;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use PDOStatement;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class MaxActiveCodesTest extends DatabaseTestCase
{
    public function testExceedingMaxActiveCodesRevokesOldest(): void
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

        for ($i = 0; $i < $policy->maxActiveCodes + 1; $i++) {
            $generator->generate(IdentityTypeEnum::User, 'user4', VerificationPurposeEnum::EmailVerification);
            $clock->modify('+' . ($policy->resendCooldownSeconds + 1) . ' seconds');
        }

        $stmt = $this->getPdo()->query('SELECT status FROM verification_codes ORDER BY created_at ASC');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        // We generated max + 1 codes. The oldest one should be revoked.
        $this->assertEquals('revoked', $rows[0]['status']);
        $this->assertEquals('active', $rows[1]['status']);
        $this->assertEquals('active', $rows[2]['status']);
        $this->assertEquals('active', $rows[3]['status']);
    }
}
