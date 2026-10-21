<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Application\Repository\EntityRepositoryInterface;

final class DbEntityRepository implements EntityRepositoryInterface
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
        private readonly ClockInterface $clock,
    ) {
    }

    public function exists(int $id): bool
    {
        return $this->db->table($this->tables->name('entities'))->where('id', $id)->countAllResults() > 0;
    }

    public function create(string $name, ?string $taxId, string $currency, string $basis): int
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->table($this->tables->name('entities'))->insert([
            'name'          => $name,
            'tax_id'        => $taxId,
            'currency'      => $currency,
            'default_basis' => $basis,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return (int) $this->db->insertID();
    }
}
