<?php

declare(strict_types=1);

namespace Maatify\Verification\Infrastructure\RateLimiter;

use Maatify\Verification\Domain\Contracts\VerificationRateLimiterInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Redis;

class RedisRateLimiter implements VerificationRateLimiterInterface
{
    private const WINDOWS = [
        '5m' => 300,
        '1h' => 3600,
        '24h' => 86400,
    ];

    private LoggerInterface $logger;

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
        ],
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
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

        // We calculate the current time block for each window.
        // This ensures counters actually reset per window.
        $now = time();
        $fieldsToUpdate = [];
        foreach (self::WINDOWS as $field => $ttl) {
            $block = (int) floor($now / $ttl);
            $fieldsToUpdate[$field] = $field . ':' . $block;
        }

        try {
            $this->redis->multi();
            foreach ($fieldsToUpdate as $field => $hashField) {
                $this->redis->hIncrBy($key, $hashField, 1);
            }
            $this->redis->expire($key, self::WINDOWS['24h'] * 2); // Ensure it lasts long enough

            /** @var array<int, int|bool>|false $results */
            $results = $this->redis->exec();

            if ($results === false || !is_array($results)) {
                // Log failure but fail open
                $this->logger->warning('Redis rate limiter transaction failed');
                return;
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
        } catch (\RedisException $e) {
            // Fail open on Redis failure
            $this->logger->warning('Redis exception in rate limiter', [
                'exception' => $e
            ]);
        }
    }
}
