<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\DTO;

readonly class VerificationPolicy
{
    /**
     * @param int $ttlSeconds Time to live in seconds
     * @param int $maxAttempts Maximum allowed attempts
     * @param int $resendCooldownSeconds Minimum seconds between resends
     * @param int $maxActiveCodes Maximum number of active codes concurrently allowed
     * @param int $maxCodesPerWindow Maximum number of codes that can be generated in the generation window
     * @param int $generationWindowMinutes The window of time in minutes to check maxCodesPerWindow
     */
    public function __construct(
        public int $ttlSeconds,
        public int $maxAttempts,
        public int $resendCooldownSeconds,
        public int $maxActiveCodes = 3,
        public int $maxCodesPerWindow = 5,
        public int $generationWindowMinutes = 15
    ) {
    }
}
