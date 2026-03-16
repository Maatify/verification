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

class ValidateSuccessTest extends DatabaseTestCase
{
    public function testCorrectCodeValidationSucceeds(): void
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
            'user1',
            VerificationPurposeEnum::EmailVerification,
            $hash,
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            $clock->now()->modify('+15 minutes'),
            $clock->now()
        );
        $repository->store($code);

        $result = $validator->validate(
            IdentityTypeEnum::User,
            'user1',
            VerificationPurposeEnum::EmailVerification,
            $plainCode
        );

        $this->assertTrue($result->success);

        $stmt = $this->getPdo()->query('SELECT status FROM verification_codes');
        $this->assertInstanceOf(PDOStatement::class, $stmt);
        $this->assertEquals('used', $stmt->fetchColumn());
    }
}
