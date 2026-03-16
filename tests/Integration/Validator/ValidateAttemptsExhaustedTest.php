<?php

declare(strict_types=1);

namespace Tests\Integration\Validator;

use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeValidator;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use PDOStatement;
use Tests\DatabaseTestCase;
use Tests\MockClock;

class ValidateAttemptsExhaustedTest extends DatabaseTestCase
{
    public function testAttemptsReachingMaxAttemptsMarksCodeExpired(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);
        $secret = 'test_secret';

        $validator = new VerificationCodeValidator(
            $repository,
            $secret
        );

        $plainCode = '123456';
        $hash = hash_hmac('sha256', $plainCode, $secret);

        $code = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user4',
            VerificationPurposeEnum::EmailVerification,
            $hash,
            VerificationCodeStatus::ACTIVE,
            2, // 2 out of 3 attempts used
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );
        $repository->store($code);

        // This will be the 3rd failed attempt
        $result = $validator->validate(
            IdentityTypeEnum::User,
            'user4',
            VerificationPurposeEnum::EmailVerification,
            '999999' // Wrong code
        );

        $this->assertFalse($result->success);

        // Verify marked as expired
        $stmt = $this->getPdo()->query('SELECT status, attempts FROM verification_codes');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $row = $stmt->fetch();

        $this->assertIsArray($row);
        $this->assertEquals(3, $row['attempts']);
        $this->assertEquals('expired', $row['status']);
    }
}
