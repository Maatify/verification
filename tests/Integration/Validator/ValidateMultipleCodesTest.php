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

class ValidateMultipleCodesTest extends DatabaseTestCase
{
    public function testValidatingNewestCodeRevokesOthers(): void
    {
        $clock = new MockClock('2025-01-01 12:00:00');
        $repository = new PdoVerificationCodeRepository($this->getPdo(), $clock);
        $secret = 'test_secret';

        $validator = new VerificationCodeValidator(
            $repository,
            $secret
        );

        // Setup two codes
        $code1 = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user6',
            VerificationPurposeEnum::EmailVerification,
            hash_hmac('sha256', '111111', $secret),
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $clock->modify('+2 minutes');

        $code2 = new VerificationCode(
            0,
            IdentityTypeEnum::User,
            'user6',
            VerificationPurposeEnum::EmailVerification,
            hash_hmac('sha256', '222222', $secret),
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );

        $repository->store($code1);
        $repository->store($code2);

        // Validate the newer code
        $result = $validator->validate(
            IdentityTypeEnum::User,
            'user6',
            VerificationPurposeEnum::EmailVerification,
            '222222'
        );

        $this->assertTrue($result->success);

        // Check DB state
        $stmt = $this->getPdo()->query('SELECT status FROM verification_codes ORDER BY created_at ASC');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $rows = $stmt->fetchAll();

        // Older code should be revoked, newer code should be used
        $this->assertEquals('revoked', $rows[0]['status']);
        $this->assertEquals('used', $rows[1]['status']);
    }
}
