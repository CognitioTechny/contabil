<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ledger;

/**
 * Result of {@see Integrity::verify()}.
 */
final class IntegrityReport
{
    /**
     * @param list<array{0: int, 1: string}> $problems Entry id and what is wrong with it.
     */
    public function __construct(
        public readonly int $checked,
        public readonly array $problems,
    ) {
    }

    public function isIntact(): bool
    {
        return $this->problems === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'checked'  => $this->checked,
            'intact'   => $this->isIntact(),
            'problems' => array_map(static fn (array $p): array => ['entry_id' => $p[0], 'message' => $p[1]], $this->problems),
        ];
    }
}
