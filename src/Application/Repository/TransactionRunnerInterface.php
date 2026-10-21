<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Repository;

/**
 * Runs a unit of work atomically: everything it writes is kept when it returns
 * and discarded when it throws (the exception is rethrown).
 */
interface TransactionRunnerInterface
{
    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function run(callable $work): mixed;
}
