<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Domain\Chart\AccountMovementInterface;

final class DbAccountMovement implements AccountMovementInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
    ) {
    }

    public function hasMovement(int $accountId): bool
    {
        return $this->db->table($this->tables->name('entry_lines'))->where('account_id', $accountId)->countAllResults() > 0;
    }
}
