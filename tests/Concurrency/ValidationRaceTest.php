<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use DateTimeImmutable;
use Maatify\SharedCommon\Contracts\ClockInterface;
use Maatify\Verification\Domain\Enum\IdentityTypeEnum;
use Maatify\Verification\Domain\Enum\VerificationPurposeEnum;
use Maatify\Verification\Domain\Service\VerificationCodeValidator;
use Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class ValidationRaceTest extends TestCase
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

        $secret = 'test_secret_key';
        $code = '123456';
        $hashedCode = hash_hmac('sha256', $code, $secret);

        $this->pdo->exec("
            INSERT INTO verification_codes
            (identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at)
            VALUES
            ('user', 'raceuser', 'email_verification', '$hashedCode', 'active', 0, 3, '2024-01-01 12:10:00', '2024-01-01 12:00:00')
        ");
    }

    public function test_validation_race_condition(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }

        $processCount = 20; // Enough to test races without overwhelming DB connections
        $children = [];

        for ($i = 0; $i < $processCount; $i++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork process');
            } elseif ($pid === 0) {
                // Child process uses its own DB connection
                try {
                    $childPdo = new PDO(
                        'mysql:host=127.0.0.1;dbname=verification_test;charset=utf8mb4',
                        'test_user',
                        'test_password',
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );

                    $clock = $this->createMock(ClockInterface::class);
                    $clock->method('getTimezone')->willReturn(new \DateTimeZone('UTC'));
                    $clock->method('now')->willReturn(new DateTimeImmutable('2024-01-01 12:05:00', new \DateTimeZone('UTC')));

                    $repository = new PdoVerificationCodeRepository($childPdo, $clock);
                    $validator = new VerificationCodeValidator($repository, 'test_secret_key');

                    $result = $validator->validate(
                        IdentityTypeEnum::User,
                        'raceuser',
                        VerificationPurposeEnum::EmailVerification,
                        '123456',
                        '127.0.0.1'
                    );

                    exit($result->success ? 0 : 1);
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

        $this->assertEquals(1, $successCount, 'Exactly one validation should succeed');
        $this->assertEquals($processCount - 1, $failureCount, 'All other validations should fail');
    }
}
