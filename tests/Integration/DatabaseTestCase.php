<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        if (!isset($this->pdo)) {
            $this->pdo = new PDO(
                'mysql:host=127.0.0.1;dbname=verification_test;charset=utf8mb4',
                'test_user',
                'test_password',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Recreate tables cleanly
            $this->pdo->exec("DROP TABLE IF EXISTS verification_codes");
            $this->pdo->exec("DROP TABLE IF EXISTS verification_generation_locks");

            $sql1 = file_get_contents(__DIR__ . '/../../database/verification_codes.sql');
            if ($sql1 !== false) $this->pdo->exec($sql1);

            $sql2 = file_get_contents(__DIR__ . '/../../database/verification_generation_locks.sql');
            if ($sql2 !== false) $this->pdo->exec($sql2);
        }

        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }

        parent::tearDown();
    }
}
