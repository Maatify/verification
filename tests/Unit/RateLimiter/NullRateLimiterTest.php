<?php

declare(strict_types=1);

namespace Tests\Unit\RateLimiter;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\RateLimiter\NullRateLimiter;
use PHPUnit\Framework\TestCase;

final class NullRateLimiterTest extends TestCase
{
    public function test_hit_performs_no_action_and_throws_no_exceptions(): void
    {
        $limiter = new NullRateLimiter();

        $limiter->hit(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertTrue(true);
    }
}
