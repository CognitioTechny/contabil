<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

/**
 * `DRAFT` is editable and outside the ledger. `POSTED` is immutable.
 * `REVERSED` is a posted entry that a reversal has cancelled; it stays in the
 * ledger together with its reversal.
 */
enum EntryStatus: string
{
    case Draft    = 'DRAFT';
    case Posted   = 'POSTED';
    case Reversed = 'REVERSED';

    /**
     * Whether the entry counts in the ledger and the reports.
     */
    public function isInLedger(): bool
    {
        return $this !== self::Draft;
    }
}
