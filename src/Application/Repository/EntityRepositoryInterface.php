<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Repository;

/**
 * Storage of accounted entities.
 */
interface EntityRepositoryInterface
{
    public function exists(int $id): bool;

    /**
     * The basis (`ACCRUAL` or `CASH`) the entity reports on by default, or null when it does not exist.
     */
    public function defaultBasis(int $id): ?string;

    /**
     * @return int Id of the new entity.
     */
    public function create(string $name, ?string $taxId, string $currency, string $basis): int;
}
