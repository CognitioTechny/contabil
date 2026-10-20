<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;

/**
 * Fixed actor. The default has no user, which suits console and test usage.
 */
final class StaticCurrentUser implements CurrentUserInterface
{
    public function __construct(private readonly int|string|null $id = null)
    {
    }

    public function id(): int|string|null
    {
        return $this->id;
    }
}
