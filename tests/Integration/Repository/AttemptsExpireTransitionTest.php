<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class AttemptsExpireTransitionTest extends DatabaseTestCase
{
    private PdoVerificationCodeRepository $repository;
    private ClockInterface $clock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->createMock(ClockInterface::class);
        $this->clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));

        $this->repository = new PdoVerificationCodeRepository($this->pdo, $this->clock);
    }

    public function test_attempts_reaching_max_attempts_sets_status_expired(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hash', 'active', 2, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 00:05:00', new \DateTimeZone('UTC')));

        $this->repository->incrementAttempts(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $stmt = $this->pdo->query('SELECT attempts, status FROM verification_codes');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(3, $row['attempts']);
        $this->assertEquals('expired', $row['status']);
    }

    public function test_attempts_not_reaching_max_keeps_active(): void
    {
        // Note: For some previous tests that didn't clean up correctly, it could affect this test.
        // Wait, DatabaseTestCase cleans up tables between tests via DROP TABLE/CREATE TABLE.
        // But max_attempts is 3. Attempts is 1. We increment. 1 + 1 = 2. It should be active!
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user_not_reach_max', 'email_verification', 'hash_test_2', 'active', 1, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 00:05:00', new \DateTimeZone('UTC')));

        $this->repository->incrementAttempts(
            IdentityTypeEnum::User,
            'user_not_reach_max',
            VerificationPurposeEnum::EmailVerification
        );

        $stmt = $this->pdo->query("SELECT attempts, status FROM verification_codes WHERE identity_id = 'user_not_reach_max'");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(2, $row['attempts']);
        $this->assertEquals('active', $row['status']);
    }
}
