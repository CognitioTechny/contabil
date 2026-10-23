<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use DateTimeImmutable;

/**
 * Criteria for listing entries; every field is optional.
 */
final class EntryFilter
{
    public function __construct(
        public readonly ?EntryStatus $status = null,
        public readonly ?DateTimeImmutable $from = null,
        public readonly ?DateTimeImmutable $to = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly ?int $accountId = null,
    ) {
    }
}
