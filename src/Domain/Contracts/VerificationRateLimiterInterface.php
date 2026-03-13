<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Contracts;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;

interface VerificationRateLimiterInterface
{
    /**
     * Records a generation request and checks if limits are exceeded.
     * Implementations may throw exceptions or handle it silently.
     */
    public function hit(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): void;
}
