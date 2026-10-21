<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Repository;

use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Fails fast when a use case is asked to work on an entity that does not exist.
 */
final class EntityGuard
{
    public function __construct(private readonly EntityRepositoryInterface $entities)
    {
    }

    /**
     * @throws NotFoundException
     */
    public function assertExists(int $entityId): void
    {
        if (! $this->entities->exists($entityId)) {
            throw new NotFoundException(sprintf('Entity %d does not exist.', $entityId));
        }
    }
}
