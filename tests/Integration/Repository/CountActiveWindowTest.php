<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class CountActiveWindowTest extends DatabaseTestCase
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

    public function test_count_active_in_window_works_correctly(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:00:00'),
            ('user', 'user123', 'email_verification', 'hash2', 'used', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:10:00'),
            ('user', 'user123', 'email_verification', 'hash3', 'revoked', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:15:00'),
            ('user', 'user123', 'email_verification', 'hash4', 'active', 0, 3, '2024-01-01 01:00:00', '2024-01-01 00:20:00'),
            ('user', 'user123', 'email_verification', 'hash5', 'active', 0, 3, '2024-01-01 01:00:00', '2023-12-31 23:50:00')
        ");

        $count = $this->repository->countActiveInWindow(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            new DateTimeImmutable('2024-01-01 00:05:00', new \DateTimeZone('UTC'))
        );

        $this->assertEquals(2, $count);
    }
}
