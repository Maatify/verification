<?php

declare(strict_types=1);

namespace Maatify\Verification\Infrastructure\Transaction;

use Maatify\Verification\Domain\Contracts\TransactionManagerInterface;
use PDO;
use Throwable;

class PdoTransactionManager implements TransactionManagerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws Throwable
     */
    public function run(callable $callback): mixed
    {
        $startedTransaction = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $result = $callback();
            if ($startedTransaction) {
                $this->pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTransaction) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
