<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\StorageInterface;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Process-local storage; contents are lost when the request ends.
 */
final class InMemoryStorage implements StorageInterface
{
    /**
     * @var array<string, string>
     */
    private array $files = [];

    public function put(string $path, string $contents): void
    {
        $this->files[$path] = $contents;
    }

    public function get(string $path): string
    {
        return $this->files[$path] ?? throw new NotFoundException(sprintf('File "%s" does not exist.', $path));
    }

    public function exists(string $path): bool
    {
        return isset($this->files[$path]);
    }

    public function delete(string $path): void
    {
        unset($this->files[$path]);
    }
}
