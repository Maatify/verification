<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class LockActiveForUpdateTest extends DatabaseTestCase
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

    public function test_lock_active_for_update_returns_active_codes(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            ('user', 'user123', 'email_verification', 'hash2', 'used', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:10:00'),
            ('user', 'user123', 'email_verification', 'hash3', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:15:00'),
            ('user', 'user123', 'password_reset', 'hash4', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:20:00')
        ");

        $codes = $this->repository->lockActiveForUpdate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertCount(2, $codes);

        // Ordered by created_at DESC
        $this->assertEquals('hash3', $codes[0]->codeHash);
        $this->assertEquals('hash1', $codes[1]->codeHash);
    }
}
