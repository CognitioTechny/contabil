<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

/**
 * Optional. Persists generated files (exports, attachments) outside the
 * database. Paths are relative, use "/" separators and never start with "/".
 */
interface StorageInterface
{
    /**
     * Writes or replaces the file.
     */
    public function put(string $path, string $contents): void;

    /**
     * @throws \CognitioTechny\Contabil\Domain\Exception\NotFoundException When the file does not exist.
     */
    public function get(string $path): string;

    public function exists(string $path): bool;

    /**
     * Deleting a missing file is not an error.
     */
    public function delete(string $path): void;
}
