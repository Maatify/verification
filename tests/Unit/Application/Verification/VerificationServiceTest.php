<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Verification;

use DateTimeImmutable;
use Maatify\Verification\Application\Exception\VerificationGenerationBlockedException;
use Maatify\Verification\Application\Exception\VerificationInternalException;
use Maatify\Verification\Application\Exception\VerificationInvalidCodeException;
use Maatify\Verification\Application\Exception\VerificationRateLimitException;
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
use RuntimeException;

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

        $this->service = new VerificationService(
            $this->generator,
            $this->validator
        );
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
            new DateTimeImmutable('+1 hour'),
            new DateTimeImmutable()
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

    public function testVerifyCodeReturnsNormallyOnValidCode(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->with(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification, '123456')
            ->willReturn(VerificationResult::success(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification));

        $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            '123456'
        );
        $this->assertTrue(true);
    }

    public function testVerifyCodeThrowsInvalidCodeOnFailure(): void
    {
        $this->validator
            ->expects($this->once())
            ->method('validate')
            ->willReturn(VerificationResult::failure('Invalid code.'));

        $this->expectException(VerificationInvalidCodeException::class);
        $this->expectExceptionMessage('Invalid verification code.');

        $this->service->verifyCode(
            IdentityTypeEnum::User,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification,
            'wrong'
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

    public function testGenerationRateLimitException(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->willThrowException(new RuntimeException('Too many codes generated in the current window.'));

        $this->expectException(VerificationRateLimitException::class);
        $this->expectExceptionMessage('Too many codes generated in the current window.');

        $this->service->startVerification(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification);
    }

    public function testGenerationBlockedException(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->willThrowException(new RuntimeException('Please wait before requesting a new code.'));

        $this->expectException(VerificationGenerationBlockedException::class);
        $this->expectExceptionMessage('Please wait before requesting a new code.');

        $this->service->startVerification(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification);
    }

    public function testInternalException(): void
    {
        $this->generator
            ->expects($this->once())
            ->method('generate')
            ->willThrowException(new RuntimeException('Failed to generate secure random code.'));

        $this->expectException(VerificationInternalException::class);
        $this->expectExceptionMessage('Failed to generate secure random code.');

        $this->service->startVerification(IdentityTypeEnum::User, 'user@example.com', VerificationPurposeEnum::EmailVerification);
    }
}
