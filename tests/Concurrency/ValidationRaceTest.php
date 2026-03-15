<?php
declare(strict_types=1);

namespace Tests\Concurrency;

use PDO;
use PHPUnit\Framework\TestCase;

class ValidationRaceTest extends TestCase
{
    private string $host;
    private string $port;
    private string $db;
    private string $user;
    private string $pass;
    private ?PDO $pdo = null;

    protected function setUp(): void
    {
        $this->host = getenv('DB_HOST') ?: '127.0.0.1';
        $this->port = getenv('DB_PORT') ?: '3306';
        $this->db   = getenv('DB_DATABASE') ?: 'testing';
        $this->user = getenv('DB_USERNAME') ?: 'root';
        $this->pass = getenv('DB_PASSWORD') ?: '';

        try {
            $this->pdo = new PDO("mysql:host={$this->host};port={$this->port}", $this->user, $this->pass);
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$this->db}`");
            $this->pdo->exec("USE `{$this->db}`");

            // Recreate tables
            $this->pdo->exec("DROP TABLE IF EXISTS `verification_codes`");
            $this->pdo->exec("DROP TABLE IF EXISTS `verification_generation_locks`");

            $codesSql = file_get_contents(__DIR__ . '/../../database/verification_codes.sql');
            if ($codesSql !== false) {
                $this->pdo->exec($codesSql);
            }

            $locksSql = file_get_contents(__DIR__ . '/../../database/verification_generation_locks.sql');
            if ($locksSql !== false) {
                $this->pdo->exec($locksSql);
            }
        } catch (\PDOException $e) {
            $this->markTestSkipped("Database connection failed: " . $e->getMessage());
        }
    }

    public function testValidationRaceCondition(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }

        if ($this->pdo === null) {
            $this->markTestSkipped('PDO is null');
        }

        $numWorkers = 50;
        $identityId = 'race_user_val_' . uniqid();
        $plainCode = '123456';
        $secret = 'secret';
        $hash = hash_hmac('sha256', $plainCode, $secret);

        // Pre-insert one code
        $stmt = $this->pdo->prepare("
            INSERT INTO verification_codes (
                identity_type, identity_id, purpose, code_hash, status, attempts, max_attempts, expires_at, created_at
            ) VALUES (
                'user', :id, 'email_verification', :hash, 'active', 0, 3, DATE_ADD(NOW(), INTERVAL 15 MINUTE), NOW()
            )
        ");
        $stmt->execute(['id' => $identityId, 'hash' => $hash]);

        $pids = [];
        $this->pdo = null; // Close parent connection

        for ($i = 0; $i < $numWorkers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Failed to fork process');
            } elseif ($pid === 0) {
                // Child process
                try {
                    // Reconnect to DB
                    $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname={$this->db}", $this->user, $this->pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => true,
                    ]);

                    require_once __DIR__ . '/../../vendor/autoload.php';

                    $clock = new \Tests\MockClock();
                    $repo = new \Maatify\Verification\Infrastructure\Repository\PdoVerificationCodeRepository($pdo, $clock);
                    $validator = new \Maatify\Verification\Domain\Service\VerificationCodeValidator($repo, $secret);

                    // Attempt to validate
                    $result = $validator->validate(
                        \Maatify\Verification\Domain\Enum\IdentityTypeEnum::User,
                        $identityId,
                        \Maatify\Verification\Domain\Enum\VerificationPurposeEnum::EmailVerification,
                        $plainCode
                    );

                    exit($result->success ? 0 : 1);
                } catch (\Exception $e) {
                    exit(2);
                }
            } else {
                // Parent process
                $pids[] = $pid;
            }
        }

        // Wait for all children to finish
        $successes = 0;
        $failures = 0;
        $errors = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCode = pcntl_wexitstatus($status);
            if ($exitCode === 0) {
                $successes++;
            } elseif ($exitCode === 1) {
                $failures++;
            } else {
                $errors++;
            }
        }

        $this->assertEquals(0, $errors, "No errors should occur during concurrent execution");
        $this->assertEquals(1, $successes, "Only 1 validation should succeed");
        $this->assertEquals($numWorkers - 1, $failures, ($numWorkers - 1) . " validations should fail");
    }
}
