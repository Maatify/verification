<?php
declare(strict_types=1);

namespace Tests\Integration\Repository;

use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use PDOStatement;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class AttemptsExpireTransitionTest extends DatabaseTestCase
{
    public function testAttemptsReachingMaxAttemptsSetsStatusExpired(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);

        $code = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user456',
            VerificationPurposeEnum::EmailVerification,
            'hash456',
            VerificationCodeStatus::ACTIVE,
            2, // Current attempts (max is 3)
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $repository->store($code);

        $repository->incrementAttempts(
            IdentityTypeEnum::User,
            'user456',
            VerificationPurposeEnum::EmailVerification
        );

        $stmt = $this->getPdo()->query("SELECT attempts, status FROM verification_codes");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        $this->assertEquals(3, $rows[0]['attempts']);
        $this->assertEquals('expired', $rows[0]['status']); // Should transition to expired
    }
}
