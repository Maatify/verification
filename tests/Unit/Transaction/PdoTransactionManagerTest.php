<?php

declare(strict_types=1);

namespace Tests\Unit\Transaction;

use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class PdoTransactionManagerTest extends TestCase
{
    public function testRunExecutesInTransaction(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->once())->method('commit');
        $pdo->expects($this->never())->method('rollBack');

        $manager = new PdoTransactionManager($pdo);

        $result = $manager->run(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    public function testRunRollsBackOnException(): void
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())->method('beginTransaction');
        $pdo->expects($this->never())->method('commit');
        $pdo->expects($this->once())->method('rollBack');

        $manager = new PdoTransactionManager($pdo);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('test rollback');

        $manager->run(function () {
            throw new RuntimeException('test rollback');
        });
    }
}
