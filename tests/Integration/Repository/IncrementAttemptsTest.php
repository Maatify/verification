<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class IncrementAttemptsTest extends DatabaseTestCase
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

    public function test_increment_attempts_targets_latest_active_code(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'old_hash', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            ('user', 'user123', 'email_verification', 'new_hash', 'active', 0, 3, '2024-01-01 01:10:00', '2024-01-01 00:10:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 00:15:00', new \DateTimeZone('UTC')));

        $this->repository->incrementAttempts(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $stmt = $this->pdo->query('SELECT code_hash, attempts FROM verification_codes ORDER BY created_at ASC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertEquals('old_hash', $rows[0]['code_hash']);
        $this->assertEquals(0, $rows[0]['attempts']); // Old one not touched

        $this->assertEquals('new_hash', $rows[1]['code_hash']);
        $this->assertEquals(1, $rows[1]['attempts']); // New one incremented
    }
}
