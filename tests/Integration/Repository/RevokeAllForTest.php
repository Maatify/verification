<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class RevokeAllForTest extends DatabaseTestCase
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

    public function test_revoke_all_for_revokes_correct_rows(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (id, identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            (1, 'user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            (2, 'user', 'user123', 'email_verification', 'hash2', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            (3, 'user', 'user123', 'password_reset', 'hash3', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            (4, 'admin', 'user123', 'email_verification', 'hash4', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->repository->revokeAllFor(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $stmt = $this->pdo->query('SELECT id, status FROM verification_codes ORDER BY id ASC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertEquals('revoked', $rows[0]['status']); // id 1
        $this->assertEquals('revoked', $rows[1]['status']); // id 2
        $this->assertEquals('active', $rows[2]['status']);  // id 3 (wrong purpose)
        $this->assertEquals('active', $rows[3]['status']);  // id 4 (wrong identity type)
    }

    public function test_revoke_all_except_ids_works(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (id, identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            (1, 'user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            (2, 'user', 'user123', 'email_verification', 'hash2', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            (3, 'user', 'user123', 'email_verification', 'hash3', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00')
        ");

        $this->repository->revokeAllFor(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            [2] // Keep ID 2
        );

        $stmt = $this->pdo->query('SELECT id, status FROM verification_codes ORDER BY id ASC');
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertEquals('revoked', $rows[0]['status']); // id 1
        $this->assertEquals('active', $rows[1]['status']);  // id 2 (excepted)
        $this->assertEquals('revoked', $rows[2]['status']); // id 3
    }
}
