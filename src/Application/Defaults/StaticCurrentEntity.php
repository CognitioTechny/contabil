<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\CurrentEntityInterface;

/**
 * Fixed entity, typically taken from the `defaultEntityId` configuration value.
 */
final class StaticCurrentEntity implements CurrentEntityInterface
{
    public function __construct(private readonly int|string|null $id = null)
    {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
