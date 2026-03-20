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

class MarkUsedAtomicTest extends DatabaseTestCase
{
    public function testMarkUsedUpdatesExactlyOneRow(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);

        $code = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash123',
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $repository->store($code);

        $result = $repository->markUsed(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash123',
            '192.168.1.1'
        );

        $this->assertSame(\Maatify\Verification\Domain\Enum\VerificationUseStatus::SUCCESS, $result->status);

        $stmt = $this->getPdo()->query('SELECT * FROM verification_codes');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        $this->assertEquals('used', $rows[0]['status']);
        $this->assertEquals('192.168.1.1', $rows[0]['used_ip']);
        $this->assertNotNull($rows[0]['used_at']);

        // Marking used again should fail
        $result2 = $repository->markUsed(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hash123',
            '192.168.1.1'
        );
        $this->assertNotSame(\Maatify\Verification\Domain\Enum\VerificationUseStatus::SUCCESS, $result2->status);
    }
}
