<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;

/**
 * Grants every permission. Suitable for console use and tests only: a host
 * that exposes the API must replace it with its own authorizer.
 */
final class AllowAllAuthorizer implements AuthorizerInterface
{
    public function can(string $permission, int|string|null $entityId = null): bool
    {
        return true;
    }
}
