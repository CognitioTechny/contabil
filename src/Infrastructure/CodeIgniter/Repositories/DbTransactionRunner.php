<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use Throwable;

/**
 * Atomic unit of work over the package database connection. Nested calls join
 * the outer transaction.
 */
final class DbTransactionRunner implements TransactionRunnerInterface
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function run(callable $work): mixed
    {
        $this->db->transBegin();

        try {
            $result = $work();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transCommit();

        return $result;
    }
}
