<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

final class ImportResult
{
    public function __construct(
        public readonly int $created,
        public readonly int $skipped,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function toArray(): array
    {
        return ['created' => $this->created, 'skipped' => $this->skipped];
    }
}
