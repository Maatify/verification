<?php

declare(strict_types=1);

namespace Tests\Unit\RateLimiter;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\RateLimiter\NullRateLimiter;
use PHPUnit\Framework\TestCase;

class NullRateLimiterTest extends TestCase
{
    public function testHitDoesNothingAndDoesNotThrow(): void
    {
        $limiter = new NullRateLimiter();

        $limiter->hit(
            IdentityTypeEnum::User,
            'user1',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertTrue(true, 'NullRateLimiter executed without exceptions');
    }
}
