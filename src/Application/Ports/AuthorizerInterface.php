<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

/**
 * Decides whether the current user holds a `contabil.*` permission string,
 * for example `contabil.chart.edit` or `contabil.reports.view`.
 *
 * Implementations must be side-effect free and must not throw for an unknown
 * permission: they answer false.
 */
interface AuthorizerInterface
{
    /**
     * @param int|string|null $entityId Entity the permission is checked against,
     *                                  or null for a package-wide permission.
     */
    public function can(string $permission, int|string|null $entityId = null): bool;
}
