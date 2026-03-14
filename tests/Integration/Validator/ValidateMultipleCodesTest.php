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

final class ValidateMultipleCodesTest extends DatabaseTestCase
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

    public function test_validating_newest_code_revokes_others(): void
    {
        $oldCode = '111111';
        $oldHashed = hash_hmac('sha256', $oldCode, $this->secret);

        $newCode = '222222';
        $newHashed = hash_hmac('sha256', $newCode, $this->secret);

        // Insert two active codes, one at 12:00, one at 12:02
        $this->pdo->exec("
            INSERT INTO verification_codes
            (id, identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            (1, 'user', 'user123', 'email_verification', '$oldHashed', 'active', 0, 3, '2024-01-01 12:10:00', '2024-01-01 12:00:00'),
            (2, 'user', 'user123', 'email_verification', '$newHashed', 'active', 0, 3, '2024-01-01 12:12:00', '2024-01-01 12:02:00')
        ");

        // Validate the NEWEST code
        $result = $this->validator->validate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            $newCode,
            '127.0.0.1'
        );

        $this->assertTrue($result->success);

        // Verify that the old code was revoked and the new code was used
        $stmt = $this->pdo->query("SELECT id, status FROM verification_codes ORDER BY id ASC");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(2, $rows);

        // First (old) code
        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals('revoked', $rows[0]['status']);

        // Second (new) code
        $this->assertEquals(2, $rows[1]['id']);
        $this->assertEquals('used', $rows[1]['status']);
    }
}
