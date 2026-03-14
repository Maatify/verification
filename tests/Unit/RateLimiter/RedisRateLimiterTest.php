<?php

declare(strict_types=1);

namespace Tests\Unit\RateLimiter;

use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Exception\VerificationRateLimitException;
use Maatify\Verification\Infrastructure\RateLimiter\RedisRateLimiter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Redis;

final class RedisRateLimiterTest extends TestCase
{
    /** @var Redis&MockObject */
    private Redis $redisMock;
    /** @var ClockInterface&MockObject */
    private ClockInterface $clockMock;
    private RedisRateLimiter $rateLimiter;

    protected function setUp(): void
    {
        if (!extension_loaded('redis')) {
            $this->markTestSkipped('The Redis extension is not available.');
        }

        $this->redisMock = $this->createMock(Redis::class);
        $this->clockMock = $this->createMock(ClockInterface::class);

        $this->rateLimiter = new RedisRateLimiter($this->redisMock, 'maatify:verification', [
            '5m' => 5,
            '1h' => 15,
            '24h' => 50,
        ], null);
    }

    public function test_hit_enforces_limits(): void
    {
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $this->clockMock->expects($this->any())->method('now')->willReturn($now);

        $this->redisMock->expects($this->once())
            ->method('multi')
            ->willReturnSelf();

        $this->redisMock->expects($this->exactly(3))
            ->method('hIncrBy')
            ->willReturn(1);

        $this->redisMock->expects($this->once())
            ->method('expire')
            ->willReturn(true);

        $this->redisMock->expects($this->once())
            ->method('exec')
            ->willReturn([3, true, 10, true, 30, true]);

        $this->rateLimiter->hit(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );

        $this->assertTrue(true);
    }

    public function test_exceeding_limits_throws_exception(): void
    {
        $now = new \DateTimeImmutable('2024-01-01 12:00:00');
        $this->clockMock->expects($this->any())->method('now')->willReturn($now);

        $this->redisMock->expects($this->any())->method('multi')->willReturnSelf();
        $this->redisMock->expects($this->any())->method('hIncrBy')->willReturn(1);
        $this->redisMock->expects($this->any())->method('expire')->willReturn(true);

        $this->redisMock->expects($this->any())->method('exec')->willReturn([6, true, 10, true, 30, true]);

        $this->expectException(\RuntimeException::class);

        $this->rateLimiter->hit(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification
        );
    }
}
