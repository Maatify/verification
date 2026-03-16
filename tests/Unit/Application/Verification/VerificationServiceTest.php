<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Verification;

use Maatify\Verification\Application\Verification\VerificationService;
use Maatify\Verification\Domain\Contracts\VerificationCodeGeneratorInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeValidatorInterface;
use Maatify\Verification\Domain\DTO\GeneratedVerificationCode;
use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\DTO\VerificationResult;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationCodeStatus;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use PHPUnit\Framework\TestCase;

class VerificationServiceTest extends TestCase
{
    private VerificationCodeGeneratorInterface&\PHPUnit\Framework\MockObject\MockObject $generator;
    private VerificationCodeValidatorInterface&\PHPUnit\Framework\MockObject\MockObject $validator;
    private VerificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->generator = $this->createMock(VerificationCodeGeneratorInterface::class);
        $this->validator = $this->createMock(VerificationCodeValidatorInterface::class);

        $this->service = new VerificationService($this->generator, $this->validator);
    }

    private function createDummyGeneratedCode(string $plainCode): GeneratedVerificationCode
    {
        $entity = new VerificationCode(
            1,
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            'hash',
            VerificationCodeStatus::ACTIVE,
            0,
            3,
            new \DateTimeImmutable('+1 hour'),
            new \DateTimeImmutable()
        );

        return new GeneratedVerificationCode($entity, $plainCode);
    }

    public function testStartVerificationReturnsGeneratedCode(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification)
            ->willReturn($this->createDummyGeneratedCode('123456'));

        $code = $this->service->startVerification(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertSame('123456', $code);
    }

    public function testVerifyCodeReturnsTrueOnValidCode(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification, '123456')
            ->willReturn(VerificationResult::success(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification));

        $result = $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            '123456'
        );

        $this->assertTrue($result);
    }

    public function testVerifyCodeThrowsExceptionOnInvalidCode(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification, 'wrong')
            ->willReturn(VerificationResult::failure('Invalid code'));

        $this->expectException(\Maatify\Verification\Application\Exceptions\VerificationInvalidCodeException::class);

        $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            'wrong'
        );
    }

    public function testVerifyCodeThrowsExceptionOnExpiredCode(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification, 'expired_code')
            ->willReturn(VerificationResult::failure('Code has expired.'));

        $this->expectException(\Maatify\Verification\Application\Exceptions\VerificationExpiredException::class);

        $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            'expired_code'
        );
    }

    public function testVerifyCodeThrowsExceptionOnAttemptsExceeded(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification, 'attempts_exceeded_code')
            ->willReturn(VerificationResult::failure('Maximum attempts exceeded.'));

        $this->expectException(\Maatify\Verification\Application\Exceptions\VerificationAttemptsExceededException::class);

        $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            'attempts_exceeded_code'
        );
    }

    public function testStartVerificationThrowsExceptionOnBlockedGeneration(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification)
            ->willThrowException(new \RuntimeException('Too many codes generated in the current window.'));

        $this->expectException(\Maatify\Verification\Application\Exceptions\VerificationGenerationBlockedException::class);

        $this->service->startVerification(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification
        );
    }

    public function testResendVerificationGeneratesNewCode(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification)
            ->willReturn($this->createDummyGeneratedCode('654321'));

        $code = $this->service->resendVerification(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertSame('654321', $code);
    }
}
