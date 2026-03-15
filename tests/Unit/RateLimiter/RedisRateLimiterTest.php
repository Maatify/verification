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

            $this->limiter = new RedisRateLimiter($this->redis, 'test_prefix');
        } catch (\Exception $e) {
            $this->markTestSkipped('Redis connection failed: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->redis) {
            try {
                $keys = $this->redis->keys('test_prefix:*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
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

            $keys = $redis->keys('test_prefix:rate:user:user1:email_verification:*');
            $this->assertNotEmpty($keys);

            $val = $redis->get($keys[0]);
            $this->assertIsString($val);
            $this->assertEquals(1, (int)$val);

            $limiter->hit(
                IdentityTypeEnum::User,
                'user1',
                VerificationPurposeEnum::EmailVerification
            );

            $val = $redis->get($keys[0]);
            $this->assertIsString($val);
            $this->assertEquals(2, (int)$val);
        } catch (\RedisException $e) {
            $this->markTestSkipped('Redis server went away during test: ' . $e->getMessage());
        }
    }
}
