<?php

declare(strict_types=1);

namespace Maatify\Verification\Infrastructure\RateLimiter;

use Maatify\Verification\Domain\Contracts\VerificationRateLimiterInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

class NullRateLimiter implements VerificationRateLimiterInterface
{
    public function hit(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): void
    {
        // Do nothing
    }
}
