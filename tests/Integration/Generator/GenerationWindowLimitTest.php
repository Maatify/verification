<?php

declare(strict_types=1);

namespace Tests\Integration\Generator;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodePolicyResolverInterface;
use Maatify\Verification\Domain\DTO\VerificationPolicy;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Exception\VerificationRateLimitException;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Infrastructure\RateLimiter\NullRateLimiter;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use Tests\Integration\DatabaseTestCase;

final class GenerationWindowLimitTest extends DatabaseTestCase
{
    private VerificationCodeGenerator $generator;
    private ClockInterface $clock;
    private string $secret = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->createMock(ClockInterface::class);
        $this->clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        // Current time is 12:00:00
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')));

        $repository = new PdoVerificationCodeRepository($this->pdo, $this->clock);
        $transactionManager = new PdoTransactionManager($this->pdo);

        $policyResolver = $this->createMock(VerificationCodePolicyResolverInterface::class);
        $policyResolver->method('resolve')->willReturn(new VerificationPolicy(
            ttlSeconds: 600,
            maxAttempts: 3,
            resendCooldownSeconds: 0,
            maxActiveCodes: 5,
            maxCodesPerWindow: 2,
            generationWindowMinutes: 60
        ));

        $this->generator = new VerificationCodeGenerator(
            $repository,
            $policyResolver,
            $this->clock,
            $transactionManager,
            $this->secret,
            new NullRateLimiter()
        );
    }

    public function test_exceeding_max_codes_per_window_throws_exception(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 11:40:00', '2024-01-01 11:30:00'),
            ('user', 'user123', 'email_verification', 'hash2', 'active', 0, 3, '2024-01-01 11:55:00', '2024-01-01 11:45:00')
        ");

        $this->expectException(VerificationRateLimitException::class);

        $this->generator->generate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            '127.0.0.1'
        );
    }
}
