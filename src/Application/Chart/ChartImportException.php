<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Raised when a chart import has at least one bad row. Nothing was imported.
 */
final class ChartImportException extends ValidationException
{
    /**
     * @param list<array{row: int, code: string|null, message: string}> $errors One entry per rejected row.
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct(sprintf('The import was rejected: %d row(s) have errors and nothing was imported.', count($errors)));
    }

    /**
     * @return list<array{row: int, code: string|null, message: string}> `row` is the 1-based position of the row in the file, header excluded.
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
