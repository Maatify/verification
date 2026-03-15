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

class StoreCodeTest extends DatabaseTestCase
{
    public function testRecordStoredCorrectly(): void
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
            $clock->now(),
            null,
            '127.0.0.1'
        );

        $repository->store($code);

        $stmt = $this->getPdo()->query("SELECT * FROM verification_codes");
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        $this->assertCount(1, $rows);
        $this->assertEquals('user123', $rows[0]['identity_id']);
        $this->assertEquals('hash123', $rows[0]['code_hash']);
        $this->assertEquals('active', $rows[0]['status']);
    }
}
