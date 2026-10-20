<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;

/**
 * Denies every permission.
 */
final class DenyAllAuthorizer implements AuthorizerInterface
{
    public function can(string $permission, int|string|null $entityId = null): bool
    {
        return false;
    }
}
