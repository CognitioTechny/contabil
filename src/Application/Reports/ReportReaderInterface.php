<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Entry\Side;
use DateTimeImmutable;

/**
 * Read-only queries behind the books and statements. Only entries in the
 * ledger (`POSTED` and `REVERSED`) are read; drafts never appear. Nothing here
 * writes, so the implementation may point at a read replica.
 *
 * A date range is inclusive on both ends and a null end is open. Sums are
 * `[debits, credits]` in minor units.
 */
interface ReportReaderInterface
{
    /**
     * Totals per account over the accrual date.
     *
     * @return array<int, array{0: int, 1: int}> Account id => [debits, credits]; only accounts with movement.
     */
    public function accrualSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array;

    /**
     * Lines of the given accounts in ledger order: accrual date, entry id, line id.
     *
     * @param list<int>                             $accountIds
     * @param array{0: string, 1: int, 2: int}|null $after      Cursor `[date, entryId, lineId]` of the last row already read.
     *
     * @return list<LedgerLine>
     */
    public function ledgerLines(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array;

    /**
     * Totals of the given accounts together, over the accrual date, optionally
     * only the rows up to and including a ledger cursor.
     *
     * @param list<int>                             $accountIds
     * @param array{0: string, 1: int, 2: int}|null $upTo
     *
     * @return array{0: int, 1: int}
     */
    public function sumsFor(int $entityId, array $accountIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $upTo = null): array;

    /**
     * Entries with their lines by accrual date, then number.
     *
     * @param array{0: string, 1: int}|null $after Cursor `[date, number]` of the last entry already read.
     *
     * @return list<JournalEntry>
     */
    public function journal(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array;

    /**
     * @return list<JournalEntry> In posting order.
     */
    public function bySource(int $entityId, string $sourceType, string $sourceId): array;

    /**
     * Revenue and expense totals per account of the entries that moved cash on
     * their own, over their cash date.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function directCashSums(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array;

    /**
     * Active settlements dated up to the end date, by date then id.
     *
     * @return list<Settlement>
     */
    public function settlementsUpTo(int $entityId, ?DateTimeImmutable $to): array;

    /**
     * For each given settled entry, its total (sum of debits) and its revenue
     * and expense lines.
     *
     * @param list<int> $entryIds
     *
     * @return array<int, array{total: int, lines: list<array{0: int, 1: Side, 2: int}>}> Entry id => profile; lines are [account id, side, amount].
     */
    public function settledProfiles(int $entityId, array $entryIds): array;
}
