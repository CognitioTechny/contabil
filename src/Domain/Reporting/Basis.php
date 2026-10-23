<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Reporting;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * When revenue and expense are recognized in a statement. The ledger is the
 * same under both; only the Income Statement changes.
 */
enum Basis: string
{
    case Accrual = 'ACCRUAL';
    case Cash    = 'CASH';

    /**
     * @param string|null $value   `accrual`, `cash` (any case), or null for the default.
     * @param string      $default The entity default, `ACCRUAL` or `CASH`.
     *
     * @throws ValidationException
     */
    public static function resolve(?string $value, string $default): self
    {
        $basis = self::tryFrom(strtoupper(trim($value === null || $value === '' ? $default : $value)));

        if ($basis === null) {
            throw new ValidationException('The basis must be accrual or cash.');
        }

        return $basis;
    }
}
