<?php

declare(strict_types=1);

namespace Tests\Integration\Generator;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Contracts\VerificationCodePolicyResolverInterface;
use Maatify\Verification\Domain\DTO\VerificationPolicy;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeGenerator;
use Maatify\Verification\Infrastructure\RateLimiter\NullRateLimiter;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use Tests\Integration\DatabaseTestCase;

final class MaxActiveCodesTest extends DatabaseTestCase
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
            maxActiveCodes: 2,
            maxCodesPerWindow: 10,
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

    public function test_exceeding_max_active_codes_revokes_oldest_codes(): void
    {
        $this->pdo->exec("
            INSERT INTO verification_codes
            (id, identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            (1, 'user', 'user123', 'email_verification', 'hash1', 'active', 0, 3, '2024-01-01 12:10:00', '2024-01-01 11:30:00'),
            (2, 'user', 'user123', 'email_verification', 'hash2', 'active', 0, 3, '2024-01-01 12:10:00', '2024-01-01 11:45:00')
        ");

        $this->generator->generate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            '127.0.0.1'
        );

        $stmt = $this->pdo->query("SELECT id, status FROM verification_codes ORDER BY id ASC");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(3, $rows);

        $this->assertEquals(1, $rows[0]['id']);
        $this->assertEquals('revoked', $rows[0]['status']);

        $this->assertEquals(2, $rows[1]['id']);
        $this->assertEquals('active', $rows[1]['status']);

        $this->assertEquals(3, $rows[2]['id']);
        $this->assertEquals('active', $rows[2]['status']);
    }
}
