<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

/**
 * Side on which an account normally increases.
 */
enum Nature: string
{
    case Debit  = 'DEBIT';
    case Credit = 'CREDIT';

    public function opposite(): self
    {
        return $this === self::Debit ? self::Credit : self::Debit;
    }
}
