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

final class GenerateSuccessTest extends DatabaseTestCase
{
    private VerificationCodeGenerator $generator;
    private ClockInterface $clock;
    private string $secret = 'test_secret_key';

    protected function setUp(): void
    {
        parent::setUp();

        $this->clock = $this->createMock(ClockInterface::class);
        $this->clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
        $this->clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')));

        $repository = new PdoVerificationCodeRepository($this->pdo, $this->clock);
        $transactionManager = new PdoTransactionManager($this->pdo);

        $policyResolver = $this->createMock(VerificationCodePolicyResolverInterface::class);
        $policyResolver->method('resolve')->willReturn(new VerificationPolicy(
            ttlSeconds: 600,
            maxAttempts: 3,
            resendCooldownSeconds: 60,
            maxActiveCodes: 2,
            maxCodesPerWindow: 5,
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

    public function test_generate_stores_code_correctly_and_returns_plain_code(): void
    {
        $result = $this->generator->generate(
            IdentityTypeEnum::User,
            'user123',
            VerificationPurposeEnum::EmailVerification,
            '127.0.0.1'
        );

        $this->assertNotEmpty($result->plainCode);
        $this->assertEquals(6, strlen($result->plainCode));
        $this->assertEquals('user123', $result->entity->identityId);
        $this->assertEquals('active', $result->entity->status->value);
        $this->assertEquals(0, $result->entity->attempts);
        $this->assertEquals(3, $result->entity->maxAttempts);

        $stmt = $this->pdo->query("SELECT * FROM verification_codes");
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $this->assertNotFalse($row);
        $this->assertEquals('user', $row['identity_type']);
        $this->assertEquals('user123', $row['identity_id']);
        $this->assertEquals('email_verification', $row['purpose']);
        $this->assertEquals('active', $row['status']);
        $this->assertEquals(0, $row['attempts']);
        $this->assertEquals(3, $row['max_attempts']);

        $expectedHash = hash_hmac('sha256', $result->plainCode, $this->secret);
        $this->assertEquals($expectedHash, $row['code_hash']);
    }
}
