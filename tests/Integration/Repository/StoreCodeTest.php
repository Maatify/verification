<?php

declare(strict_types=1);

namespace Tests\Integration\Repository;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class StoreCodeTest extends DatabaseTestCase
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

    public function test_store_saves_code_record_correctly(): void
    {
        $code = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            'hashed_code',
            VerificationCodeStatus::from('active'),
            0,
            3,
            new DateTimeImmutable('2024-01-01 01:00:00', new \DateTimeZone('UTC')),
            new DateTimeImmutable('2024-01-01 00:00:00', new \DateTimeZone('UTC')),
            null,
            '127.0.0.1'
        );

        $this->repository->store($code);

        $stmt = $this->pdo->query('SELECT * FROM verification_codes');
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertEquals('user', $row['identity_type']);
        $this->assertEquals('user123', $row['identity_id']);
        $this->assertEquals('email_verification', $row['purpose']);
        $this->assertEquals('hashed_code', $row['code_hash']);
        $this->assertEquals('active', $row['status']);
        $this->assertEquals(0, $row['attempts']);
        $this->assertEquals(3, $row['max_attempts']);
        $this->assertEquals('2024-01-01 01:00:00', $row['expires_at']);
        $this->assertEquals('2024-01-01 00:00:00', $row['created_at']);
        $this->assertEquals('127.0.0.1', $row['created_ip']);
        $this->assertNull($row['used_at']);
        $this->assertNull($row['used_ip']);
    }
}
