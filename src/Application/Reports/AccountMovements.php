<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Money;
use CognitioTechny\Contabil\Domain\Reporting\Basis;
use DateTimeImmutable;

/**
 * Movement per account over a date range under a basis.
 *
 * Accrual reads the ledger by accrual date. Cash keeps only revenue and
 * expense accounts and recognizes them when cash moves:
 *
 * - An entry that moved cash on its own (it has a cash date) counts in full on that date.
 * - Any other entry counts through its settlements. Each settlement recognizes
 *   `line amount * settled so far / entry total`, rounded half away from zero,
 *   less what earlier settlements recognized. The final settlement therefore
 *   carries the rounding residual and the full amount is always recognized.
 * - Settlements of reversed settlement entries are void; entries never settled count nowhere.
 */
final class AccountMovements
{
    public function __construct(private readonly ReportReaderInterface $reader)
    {
    }

    /**
     * @return array<int, array{0: int, 1: int}> Account id => [debits, credits].
     */
    public function for(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, Basis $basis): array
    {
        if ($basis === Basis::Accrual) {
            return $this->reader->accrualSums($entityId, $from, $to);
        }

        $sums = $this->reader->directCashSums($entityId, $from, $to);

        foreach ($this->settlementRecognition($entityId, $from, $to) as [$accountId, $side, $amount]) {
            $sums[$accountId] ??= [0, 0];
            $sums[$accountId][$side === Side::Debit ? 0 : 1] += $amount;
        }

        return $sums;
    }

    /**
     * @return list<array{0: int, 1: Side, 2: int}> Account id, side, amount recognized inside the range.
     */
    private function settlementRecognition(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        $byEntry = [];

        foreach ($this->reader->settlementsUpTo($entityId, $to) as $settlement) {
            $byEntry[$settlement->settledEntryId][] = $settlement;
        }

        if ($byEntry === []) {
            return [];
        }

        $profiles   = $this->reader->settledProfiles($entityId, array_keys($byEntry));
        $recognized = [];

        foreach ($byEntry as $entryId => $settlements) {
            $profile = $profiles[$entryId] ?? null;

            if ($profile === null || $profile['lines'] === []) {
                continue;
            }

            $settled = 0;
            $before  = array_fill(0, count($profile['lines']), 0);

            foreach ($settlements as $settlement) {
                $settled += $settlement->amount;
                $inRange = $from === null || $settlement->date >= $from;

                foreach ($profile['lines'] as $index => [$accountId, $side, $amount]) {
                    $total = Money::proportion($amount, min($settled, $profile['total']), $profile['total']);
                    $delta = $total - $before[$index];

                    $before[$index] = $total;

                    if ($inRange && $delta !== 0) {
                        $recognized[] = [$accountId, $side, $delta];
                    }
                }
            }
        }

        return $recognized;
    }
}
