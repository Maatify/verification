<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodePolicyResolverInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodeRepositoryInterface;
use Maatify\Verification\Domain\DTO\VerificationCode;
use Maatify\Verification\Domain\DTO\VerificationPolicy;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use PHPUnit\Framework\TestCase;

final class VerificationCodeGeneratorTest extends TestCase
{
    public function test_it_generates_verification_code(): void
    {
        $repo = $this->createMock(VerificationCodeRepositoryInterface::class);

        $repo->expects($this->once())
            ->method('countActiveInWindow')
            ->willReturn(0);

        $repo->expects($this->once())
            ->method('findAllActive')
            ->willReturn([]);

        $repo->expects($this->once())
            ->method('store')
            ->with($this->callback(function (VerificationCode $code) {
                return $code->identityId === 'user@example.com';
            }));

        $resolver = $this->createMock(VerificationCodePolicyResolverInterface::class);

        $resolver->method('resolve')
            ->willReturn(new VerificationPolicy(
                ttlSeconds: 600,
                maxAttempts: 3,
                resendCooldownSeconds: 60
            ));

        $clock = $this->createMock(ClockInterface::class);

        $clock->method('now')
            ->willReturn(new \DateTimeImmutable());

        $generator = new VerificationCodeGenerator(
            $repo,
            $resolver,
            $clock
        );

        $result = $generator->generate(
            IdentityTypeEnum::Email,
            'user@example.com',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertNotEmpty($result->plainCode);
        $this->assertEquals(6, strlen($result->plainCode));
    }
}
