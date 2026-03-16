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

class RevokeAllForTest extends DatabaseTestCase
{
    public function testRevokeAllForRevokesCorrectRows(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);

        // Store two codes
        $code1 = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash1',
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $code2 = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash2',
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $repository->store($code1);
        $repository->store($code2);

        $stmt = $this->getPdo()->query('SELECT id FROM verification_codes ORDER BY id DESC LIMIT 1');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $lastId = (int)$stmt->fetchColumn();

        $repository->revokeAllFor(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            [$lastId]
        );

        $stmt = $this->getPdo()->query('SELECT status FROM verification_codes ORDER BY id ASC');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        $this->assertEquals('revoked', $rows[0]['status']);
        $this->assertEquals('active', $rows[1]['status']);
    }
}
