<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class MarkUsedAtomicTest extends DatabaseTestCase
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

    public function test_mark_used_updates_exactly_one_row(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hashed_code', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 00:05:00', new \DateTimeZone('UTC')));

        $result = $this->repository->markUsed(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hashed_code',
            '192.168.1.1'
        );

        $this->assertTrue($result);

        $stmt = $this->pdo->query('SELECT * FROM verification_codes');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('used', $row['status']);
        $this->assertEquals('2024-01-01 00:05:00', $row['used_at']);
        $this->assertEquals('192.168.1.1', $row['used_ip']);
    }

    public function test_mark_used_returns_false_for_invalid_code(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hashed_code', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 00:05:00', new \DateTimeZone('UTC')));

        $result = $this->repository->markUsed(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'wrong_code',
            '192.168.1.1'
        );

        $this->assertFalse($result);

        $stmt = $this->pdo->query('SELECT status FROM verification_codes');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals('active', $row['status']);
    }

    public function test_mark_used_returns_false_for_expired_code(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hashed_code', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 02:00:00', new \DateTimeZone('UTC')));

        $result = $this->repository->markUsed(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hashed_code',
            '192.168.1.1'
        );

        $this->assertFalse($result);
    }
}
