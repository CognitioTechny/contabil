<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

/**
 * Identifies who is acting. The identifier is opaque to the package: it is
 * stored as `user_id` on entries and never joined to a host table.
 */
interface CurrentUserInterface
{
    /**
     * @return int|string|null Null when there is no authenticated actor
     *                         (console, queue worker, anonymous request).
     */
    public function id(): int|string|null;
}
