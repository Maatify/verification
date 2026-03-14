<?php

declare(strict_types=1);

namespace Tests\Unit\Transaction;

use Maatify\Verification\Infrastructure\Transaction\PdoTransactionManager;
use PDO;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class TransactionManagerTest extends TestCase
{
    /** @var PDO&MockObject */
    private PDO $pdo;
    private PdoTransactionManager $transactionManager;

    protected function setUp(): void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->transactionManager = new PdoTransactionManager($this->pdo);
    }

    public function test_transaction_commit_works_and_returns_callback_result(): void
    {
        $this->pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('commit')->willReturn(true);
        $this->pdo->expects($this->never())->method('rollBack');

        $result = $this->transactionManager->run(function () {
            return 'success_result';
        });

        $this->assertEquals('success_result', $result);
    }

    public function test_rollback_occurs_on_exception(): void
    {
        $this->pdo->expects($this->once())->method('beginTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('rollBack')->willReturn(true);
        $this->pdo->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test Exception');

        $this->transactionManager->run(function () {
            throw new \RuntimeException('Test Exception');
        });
    }
}
