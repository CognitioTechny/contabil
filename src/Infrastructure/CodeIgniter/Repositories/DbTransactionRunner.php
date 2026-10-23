<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use Throwable;

/**
 * Atomic unit of work over the package database connection. Nested calls join
 * the outer transaction.
 *
 * SQLite starts transactions as deferred, so two writers that both read first
 * can fail with "database is locked" when they try to upgrade. On SQLite the
 * outermost transaction therefore takes the write lock at once, with a
 * statement that changes nothing, which is what an immediate transaction does.
 */
final class DbTransactionRunner implements TransactionRunnerInterface
{
    /**
     * @param string|null $writeLockTable Any package table; used only on SQLite.
     */
    public function __construct(
        private readonly BaseConnection $db,
        private readonly ?string $writeLockTable = null,
    ) {
    }

    public function run(callable $work): mixed
    {
        $outermost = $this->db->transDepth === 0;

        $this->db->transBegin();

        try {
            if ($outermost && $this->writeLockTable !== null && $this->db->getPlatform() === 'SQLite3') {
                $this->db->query('UPDATE ' . $this->db->protectIdentifiers($this->writeLockTable, true) . ' SET version = version WHERE 1 = 0');
            }

            $result = $work();
        } catch (Throwable $e) {
            $this->db->transRollback();

            throw $e;
        }

        $this->db->transCommit();

        return $result;
    }
}
