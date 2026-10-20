<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

/**
 * Tells the package which accounted entity the current request works on.
 */
interface CurrentEntityInterface
{
    /**
     * @return int|string|null Null when no entity is selected; callers must then
     *                         receive the entity explicitly or fail with a
     *                         ValidationException.
     */
    public function id(): int|string|null;
}
