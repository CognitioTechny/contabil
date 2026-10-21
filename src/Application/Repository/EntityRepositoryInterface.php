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
     * @return int Id of the new entity.
     */
    public function create(string $name, ?string $taxId, string $currency, string $basis): int;
}
