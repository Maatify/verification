<?php
declare(strict_types=1);

namespace Tests;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    private static ?PDO $sharedPdo = null;

    protected function setUp(): void
    {
        $pdo = $this->getPdo();
        // Since PDOTransactionManager doesn't support nested transactions well,
        // and we want isolated tests, we should just truncate tables before each test
        // rather than using BEGIN/ROLLBACK transactions.

        $pdo->exec("DELETE FROM `verification_codes`");
        $pdo->exec("DELETE FROM `verification_generation_locks`");
    }

    protected function getPdo(): PDO
    {
        if (self::$sharedPdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $port = getenv('DB_PORT') ?: '3306';
            $db   = getenv('DB_DATABASE') ?: 'testing';
            $user = getenv('DB_USERNAME') ?: 'root';
            $pass = getenv('DB_PASSWORD') ?: '';

            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

            try {
                // Try to create the DB first if it doesn't exist
                $tempPdo = new PDO("mysql:host=$host;port=$port", $user, $pass);
                $tempPdo->exec("CREATE DATABASE IF NOT EXISTS `$db`");

                self::$sharedPdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => true,
                ]);
            } catch (\PDOException $e) {
                $this->markTestSkipped("Database connection failed: " . $e->getMessage());
            }

            // Apply schema
            self::$sharedPdo->exec("DROP TABLE IF EXISTS `verification_codes`");
            self::$sharedPdo->exec("DROP TABLE IF EXISTS `verification_generation_locks`");

            $codesSql = file_get_contents(__DIR__ . '/../database/verification_codes.sql');
            if ($codesSql !== false) {
                self::$sharedPdo->exec($codesSql);
            }

            $locksSql = file_get_contents(__DIR__ . '/../database/verification_generation_locks.sql');
            if ($locksSql !== false) {
                self::$sharedPdo->exec($locksSql);
            }
        }

        return self::$sharedPdo;
    }
}
