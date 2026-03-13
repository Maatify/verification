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
        $this->pdo->beginTransaction();

        try {
            $result = $callback();
            $this->pdo->commit();
            return $result;
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
