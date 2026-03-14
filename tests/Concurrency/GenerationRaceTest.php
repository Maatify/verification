<?php

declare(strict_types=1);

namespace Tests\Concurrency;

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
use PDO;
use PHPUnit\Framework\TestCase;

final class GenerationRaceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=verification_test;charset=utf8mb4',
            'test_user',
            'test_password',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $this->pdo->exec("DROP TABLE IF EXISTS verification_codes");
        $this->pdo->exec("DROP TABLE IF EXISTS verification_generation_locks");
        $this->pdo->exec(file_get_contents(__DIR__ . '/../../database/verification_codes.sql'));
        $this->pdo->exec(file_get_contents(__DIR__ . '/../../database/verification_generation_locks.sql'));
    }

    public function test_generation_race_condition(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }

        $processCount = 20;
        $children = [];

        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork process');
            } elseif ($pid === 0) {
                // Child process
                try {
                    $childPdo = new PDO(
                        'mysql:host=127.0.0.1;dbname=verification_test;charset=utf8mb4',
                        'test_user',
                        'test_password',
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    $clock = $this->createMock(ClockInterface::class);
                    $clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
                    $clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 12:00:00', new \DateTimeZone('UTC')));

                    $repository = new PdoVerificationCodeRepository($childPdo, $clock);
                    $transactionManager = new PdoTransactionManager($childPdo);

                    $policyResolver = $this->createMock(VerificationCodePolicyResolverInterface::class);
                    $policyResolver->method('resolve')->willReturn(new VerificationPolicy(
                        ttlSeconds: 600,
                        maxAttempts: 3,
                        resendCooldownSeconds: 60,
                        maxActiveCodes: 2,
                        maxCodesPerWindow: 5,
                        generationWindowMinutes: 60
                    ));

                    $generator = new VerificationCodeGenerator(
                        $repository,
                        $policyResolver,
                        $clock,
                        $transactionManager,
                        'secret',
                        new NullRateLimiter()
                    );

                    $generator->generate(
                        IdentityTypeEnum::User,
                        'genraceuser',
                        VerificationPurposeEnum::EmailVerification,
                        '127.0.0.1'
                    );

                    exit(0);
                } catch (\Exception $e) {
                    exit(1);
                }
            } else {
                $children[] = $pid;
            }
        }

        $successCount = 0;
        $failureCount = 0;

        foreach ($children as $childPid) {
            pcntl_waitpid($childPid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        // Only 1 record should be generated
        // Re-establish PDO connection to avoid "server has gone away" due to forks
        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=verification_test;charset=utf8mb4',
            'test_user',
            'test_password',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $stmt = $pdo->query("SELECT count(*) FROM verification_codes WHERE identity_id = 'genraceuser'");
        $count = (int)$stmt->fetchColumn();

        $this->assertEquals(1, $count, "Only one code should be created due to generation locks");
        $this->assertEquals(1, $successCount, 'Exactly one generation process should succeed');
        $this->assertEquals($processCount - 1, $failureCount, 'All other generation processes should fail');

        // Verify the mutex row was successfully persisted
        $lockStmt = $pdo->query("SELECT * FROM verification_generation_locks WHERE identity_id = 'genraceuser'");
        $lockRow = $lockStmt->fetch(PDO::FETCH_ASSOC);

        $this->assertNotFalse($lockRow, "Generation lock mutex row was not created!");
        $this->assertEquals('user', $lockRow['identity_type']);
        $this->assertEquals('email_verification', $lockRow['purpose']);
        $this->assertEquals('2024-01-01 12:00:00', $lockRow['locked_at']);
    }
}
