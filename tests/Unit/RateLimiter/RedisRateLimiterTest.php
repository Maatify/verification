<?php
declare(strict_types=1);

namespace Tests\Unit\RateLimiter;

use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Infrastructure\RateLimiter\RedisRateLimiter;
use PHPUnit\Framework\TestCase;

class RedisRateLimiterTest extends TestCase
{
    private ?\Redis $redis = null;
    private ?RedisRateLimiter $limiter = null;
    private string $prefix = 'test_prefix';

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('Redis extension not installed.');
        }

        try {
            $this->redis = new \Redis();
            $host = getenv('REDIS_HOST') ?: '127.0.0.1';
            $port = (int)(getenv('REDIS_PORT') ?: 6379);

            if (!@$this->redis->connect($host, $port)) {
                $this->markTestSkipped('Could not connect to Redis server.');
            }

            // Simple ping to ensure connection is actually alive
            if (!@$this->redis->ping()) {
                $this->markTestSkipped('Redis server is not responding to ping.');
            }

            $this->limiter = new RedisRateLimiter($this->redis, $this->prefix);
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            try {
                // Instead of KEYS, we can use an iterator (SCAN) to safely find keys, or just
                // manually delete the exact keys we know we created.
                $iterator = null;

                do {
                    $keys = $this->redis->scan($iterator, $this->prefix . ':*');

                    if ($keys !== false) {
                        $this->redis->del($keys);
                    }

                } while ($iterator !== 0);
            } catch (\Exception $e) {
                // Ignore cleanup errors if server went away
            } finally {
                try {
                    $this->redis->close();
                } catch (\Exception $e) {}
            }
        }
    }

    public function testRateLimiterRecordsHit(): void
    {
        if (!$this->limiter || !$this->redis) {
            $this->markTestSkipped('Redis not available');
        }

        /** @var \Redis $redis */
        $redis = $this->redis;
        /** @var RedisRateLimiter $limiter */
        $limiter = $this->limiter;

        try {
            $limiter->hit(
                IdentityTypeEnum::User,
                'user1',
                VerificationPurposeEnum::EmailVerification
            );

            // Fetch keys safely via SCAN to avoid KEYS O(N) operation
            $iterator = null;
            $foundKeys = [];

            do {
                $scannedKeys = $redis->scan($iterator, $this->prefix . ':rate:user:user1:email_verification:*');

                if ($scannedKeys !== false) {
                    foreach ($scannedKeys as $k) {
                        $foundKeys[] = $k;
                    }
                }

            } while ($iterator !== 0);

            $this->assertNotEmpty($foundKeys);

            $val = $redis->get($foundKeys[0]);
            $this->assertIsString($val);
            $this->assertEquals(1, (int)$val);

            $limiter->hit(
                IdentityTypeEnum::User,
                'user1',
                VerificationPurposeEnum::EmailVerification
            );

            $val = $redis->get($foundKeys[0]);
            $this->assertIsString($val);
            $this->assertEquals(2, (int)$val);
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis server went away during test: ' . $e->getMessage());
        }
    }
}
