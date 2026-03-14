<?php

declare(strict_types=1);

namespace Tests\Integration\Validator;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeValidator;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Tests\Integration\DatabaseTestCase;

final class ValidateWrongCodeTest extends DatabaseTestCase
{
    private VerificationCodeValidator $validator;
    private ClockInterface $clock;
    private string $secret = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->createMock(ClockInterface::class);
        $this->clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        // Current time is 12:05:00
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 12:05:00', new \DateTimeZone('UTC')));

        $repository = new PdoVerificationCodeRepository($this->pdo, $this->clock);

        $this->validator = new VerificationCodeValidator(
            $repository,
            $this->secret
        );
    }

    public function test_wrong_code_increments_attempts(): void
    {
        $correctCode = '123456';
        $hashedCode = hash_hmac('sha256', $correctCode, $this->secret);
        $wrongCode = '654321';

        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', '$hashedCode', 'active', 0, 3, '2024-01-01 12:10:00', '2024-01-01 12:00:00')
        ");

        $result = $this->validator->validate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            $wrongCode,
            '127.0.0.1'
        );

        $this->assertFalse($result->success);

        // Verify attempts incremented in DB
        $stmt = $this->pdo->query("SELECT attempts, status FROM verification_codes");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertEquals(1, $row['attempts']);
        $this->assertEquals('active', $row['status']);
    }

    public function test_validating_nonexistent_code_returns_invalid(): void
    {
        $result = $this->validator->validate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            '123456',
            '127.0.0.1'
        );

        $this->assertFalse($result->success);
    }
}
