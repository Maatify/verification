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

class GenerationLockTest extends DatabaseTestCase
{
    public function testVerifyRowExistsInVerificationGenerationLocks(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);
        $generator = new VerificationCodeGenerator(
            $repository,
            new VerificationCodePolicyResolver(),
            $clock,
            new PdoTransactionManager($this->getPdo()),
            'test_secret'
        );

        $generator->generate(IdentityTypeEnum::User, 'user_lock', VerificationPurposeEnum::EmailVerification);

        $stmt = $this->getPdo()->query('SELECT * FROM verification_generation_locks');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertEquals('user', $rows[0]['identity_type']);
        $this->assertEquals('user_lock', $rows[0]['identity_id']);
    }
}
