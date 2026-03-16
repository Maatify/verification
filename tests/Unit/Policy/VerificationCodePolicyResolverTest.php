<?php

declare(strict_types=1);

namespace Tests\Unit\Policy;

use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use PHPUnit\Framework\TestCase;

class VerificationCodePolicyResolverTest extends TestCase
{
    public function testResolveEmailVerification(): void
    {
        $resolver = new VerificationCodePolicyResolver();
        $policy = $resolver->resolve(VerificationPurposeEnum::EmailVerification);

        $this->assertEquals(600, $policy->ttlSeconds);
        $this->assertEquals(3, $policy->maxAttempts);
        $this->assertEquals(60, $policy->resendCooldownSeconds);
    }

    public function testResolveTelegramChannelLink(): void
    {
        $resolver = new VerificationCodePolicyResolver();
        $policy = $resolver->resolve(VerificationPurposeEnum::TelegramChannelLink);

        $this->assertEquals(300, $policy->ttlSeconds);
        $this->assertEquals(3, $policy->maxAttempts);
        $this->assertEquals(60, $policy->resendCooldownSeconds);
    }
}
