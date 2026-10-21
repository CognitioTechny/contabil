<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Ports\AuthorizerInterface;

/**
 * Grants exactly the permissions it was built with, and records every check.
 */
final class FakeAuthorizer implements AuthorizerInterface
{
    /** @var list<array{string, int|string|null}> */
    public array $checks = [];

    /**
     * @param list<string> $granted
     */
    public function __construct(private readonly array $granted)
    {
    }

    public function can(string $permission, int|string|null $entityId = null): bool
    {
        $this->checks[] = [$permission, $entityId];

        return in_array($permission, $this->granted, true);
    }
}
