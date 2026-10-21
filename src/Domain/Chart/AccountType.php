<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Classification of an account. The nature (debit or credit) derives from it;
 * a contra account takes the opposite nature of its type.
 */
enum AccountType: string
{
    case Asset     = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity    = 'EQUITY';
    case Revenue   = 'REVENUE';
    case Expense   = 'EXPENSE';

    public function defaultNature(): Nature
    {
        return match ($this) {
            self::Asset, self::Expense                      => Nature::Debit,
            self::Liability, self::Equity, self::Revenue    => Nature::Credit,
        };
    }

    /**
     * Nature of an account of this type, flipped when it is a contra account.
     */
    public function natureFor(bool $contra): Nature
    {
        return $contra ? $this->defaultNature()->opposite() : $this->defaultNature();
    }

    public static function fromInput(string $value): self
    {
        $type = self::tryFrom(strtoupper(trim($value)));

        if ($type === null) {
            throw new ValidationException(sprintf(
                'Unknown account type "%s"; expected one of %s.',
                $value,
                implode(', ', array_column(self::cases(), 'value')),
            ));
        }

        return $type;
    }
}
