<?php

declare(strict_types=1);

namespace Maatify\Verification\Infrastructure\RateLimiter;

use Maatify\Verification\Domain\Contracts\VerificationRateLimiterInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use RuntimeException;
use Redis;

class RedisRateLimiter implements VerificationRateLimiterInterface
{
    private const WINDOWS = [
        '5m' => 300,
        '1h' => 3600,
        '24h' => 86400,
    ];

    /**
     * @param array<string, int> $limits
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly string $prefix = 'maatify:verification',
        private readonly array $limits = [
            '5m' => 5,
            '1h' => 15,
            '24h' => 50,
        ]
    ) {
    }

    public function hit(IdentityTypeEnum $identityType, string $identityId, VerificationPurposeEnum $purpose): void
    {
        $key = sprintf(
            '%s:rate:%s:%s:%s',
            $this->prefix,
            $identityType->value,
            $identityId,
            $purpose->value
        );

        // We are using a simple rolling window approximation with Hashes
        // A more complex implementation might use Sorted Sets, but for simplicity
        // as per instructions we use Hash to store multiple counters.

        // We calculate the current time block for each window.
        // This ensures counters actually reset per window.
        $now = time();
        $fieldsToUpdate = [];
        foreach (self::WINDOWS as $field => $ttl) {
            $block = (int) floor($now / $ttl);
            $fieldsToUpdate[$field] = $field . ':' . $block;
        }

        $this->redis->multi();
        foreach ($fieldsToUpdate as $field => $hashField) {
            $this->redis->hIncrBy($key, $hashField, 1);
        }
        $this->redis->expire($key, self::WINDOWS['24h'] * 2); // Ensure it lasts long enough

        /** @var array<int, int|bool>|false $results */
        $results = $this->redis->exec();

        if ($results === false || !is_array($results)) {
            throw new RuntimeException('Failed to execute Redis transaction for rate limiting.');
        }

        $i = 0;
        foreach ($fieldsToUpdate as $field => $hashField) {
            $currentHits = $results[$i];

            // Check if limits exceeded
            if (isset($this->limits[$field]) && $currentHits > $this->limits[$field]) {
                throw new RuntimeException(sprintf('Rate limit exceeded for window %s', $field));
            }
            $i++;
        }

        // As an optimization, we can asynchronously delete old fields from the hash,
        // but Redis expiry on the key handles the ultimate cleanup.
    }
}
