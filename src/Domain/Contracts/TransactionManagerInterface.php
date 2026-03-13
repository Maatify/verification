<?php

declare(strict_types=1);

namespace Maatify\Verification\Domain\Contracts;

interface TransactionManagerInterface
{
    /**
     * Executes the given callback within a transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function run(callable $callback): mixed;
}
