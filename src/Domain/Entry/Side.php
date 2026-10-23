<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Side of an entry line.
 */
enum Side: string
{
    case Debit  = 'D';
    case Credit = 'C';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }

    public static function fromInput(mixed $value): self
    {
        $side = is_string($value) ? match (strtoupper(trim($value))) {
            'D', 'DEBIT'  => self::Debit,
            'C', 'CREDIT' => self::Credit,
            default       => null,
        } : null;

        if ($side === null) {
            throw new ValidationException('The side of a line must be D (debit) or C (credit).');
        }

        return $side;
    }
}
