<?php
declare(strict_types=1);

namespace Tests\Concurrency;

use PDO;
use PHPUnit\Framework\TestCase;

class GenerationRaceTest extends TestCase
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

            // Clear tables instead of recreating
            $this->pdo->exec("DELETE FROM `verification_codes`");
            $this->pdo->exec("DELETE FROM `verification_generation_locks`");
        } catch (\PDOException $e) {
            $this->markTestSkipped("Database connection failed: " . $e->getMessage());
        }
    }

    public function testGenerationRaceCondition(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for concurrency tests');
        }

        $numWorkers = 20;
        $identityId = 'race_user_' . uniqid();
        $pids = [];

        // Close PDO before forking
        $this->pdo = null;

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
                    $policyResolver = new \Maatify\Verification\Domain\Service\VerificationCodePolicyResolver();
                    $transactionManager = new \Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager($pdo);
                    $generator = new \Maatify\Verification\Domain\Service\VerificationCodeGenerator(
                        $repo, $policyResolver, $clock, $transactionManager, 'secret'
                    );

                    // Attempt to generate
                    $generator->generate(
                        \Maatify\Verification\Domain\Enum\IdentityTypeEnum::User,
                        $identityId,
                        \Maatify\Verification\Domain\Enum\VerificationPurposeEnum::EmailVerification
                    );
                    exit(0); // Success
                } catch (\Exception $e) {
                    exit(1); // Failure
                }
            } else {
                $pids[] = $pid;
            }
        }

        // Wait for all children to finish
        $successes = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $successes++;
            }
        }

        // Connect again to verify
        $pdo = new PDO("mysql:host={$this->host};port={$this->port};dbname={$this->db}", $this->user, $this->pass);

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM verification_codes WHERE identity_id = ?");
        $stmt->execute([$identityId]);
        $this->assertEquals(1, $stmt->fetchColumn(), "Only one code should be in the database");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM verification_generation_locks WHERE identity_id = ?");
        $stmt->execute([$identityId]);
        $this->assertEquals(1, $stmt->fetchColumn(), "One lock should exist");

        $this->assertEquals(1, $successes, "Only one concurrent generation should succeed");
    }
}
