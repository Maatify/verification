<?php

declare(strict_types=1);

namespace Tests\Unit;

use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodePolicyResolver;
use PHPUnit\Framework\TestCase;

final class PolicyResolverTest extends TestCase
{
    public function test_correct_policy_returned_per_purpose(): void
    {
        $resolver = new VerificationCodePolicyResolver();

        // Email Verification Policy
        $policyEmail = $resolver->resolve(VerificationPurposeEnum::EmailVerification);

        $this->assertEquals(600, $policyEmail->ttlSeconds);
        $this->assertEquals(3, $policyEmail->maxAttempts);
        $this->assertEquals(60, $policyEmail->resendCooldownSeconds);

        // Telegram Channel Link Policy
        $policyTelegram = $resolver->resolve(VerificationPurposeEnum::TelegramChannelLink);

        $this->assertEquals(300, $policyTelegram->ttlSeconds);
        $this->assertEquals(3, $policyTelegram->maxAttempts);
        $this->assertEquals(60, $policyTelegram->resendCooldownSeconds);
    }
}
