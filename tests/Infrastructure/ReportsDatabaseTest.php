<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use DateTimeImmutable;
use Tests\Support\DbLedgerTestCase;

/**
 * The reference dataset of the in-memory report tests, run against the real
 * queries, plus the size target for a large ledger.
 *
 * @internal
 */
final class ReportsDatabaseTest extends DbLedgerTestCase
{
    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    private function dataset(): void
    {
        $this->posted('2026-03-01', 'Capital', [['1.1.01', 'D', 100000], ['3.1.01', 'C', 100000]], '2026-03-01');
        $two = $this->posted('2026-03-05', 'Credit sale', [['1.1.02', 'D', 10000], ['4.1.01', 'C', 10000]]);
        $this->posted('2026-03-10', 'Cash sale', [['1.1.01', 'D', 3000], ['4.1.01', 'C', 3000]], '2026-03-10');
        $four = $this->posted('2026-03-15', 'Credit purchase', [['5.1.02', 'D', 4000], ['2.1.01', 'C', 4000]]);
        $five = $this->posted('2026-03-18', 'Wrong cash sale', [['1.1.01', 'D', 800], ['4.1.01', 'C', 800]], '2026-03-18');
        $this->uc()->reverseEntry()->execute($this->entity, (int) $five->id, 'Wrong customer', '2026-03-19');
        $this->posted('2026-03-20', 'Rent paid', [['5.1.01', 'D', 1500], ['1.1.01', 'C', 1500]], '2026-03-20');
        $eight = $this->posted('2026-03-25', 'Collection part', [['1.1.01', 'D', 4000], ['1.1.02', 'C', 4000]], '2026-03-25');
        $nine  = $this->posted('2026-04-10', 'Collection rest', [['1.1.01', 'D', 6000], ['1.1.02', 'C', 6000]], '2026-04-10');
        $ten   = $this->posted('2026-04-12', 'Supplier paid', [['2.1.01', 'D', 4000], ['1.1.01', 'C', 4000]], '2026-04-12');

        $this->uc()->settleEntry()->execute($this->entity, (int) $eight->id, (int) $two->id, 4000);
        $this->uc()->settleEntry()->execute($this->entity, (int) $nine->id, (int) $two->id, 6000);
        $this->uc()->settleEntry()->execute($this->entity, (int) $ten->id, (int) $four->id, 4000);
    }

    /**
     * @return array<string, int>
     */
    private function totals(string $from, string $to, string $basis): array
    {
        return array_filter($this->uc()->statements()->incomeStatement($this->entity, $this->date($from), $this->date($to), $basis)->totals, 'is_int');
    }

    public function testIncomeStatementUnderBothBases(): void
    {
        $this->dataset();

        $this->assertSame(['revenue' => 13000, 'expenses' => 5500, 'net_result' => 7500], $this->totals('2026-03-01', '2026-03-31', 'accrual'));
        $this->assertSame(['revenue' => 7000, 'expenses' => 1500, 'net_result' => 5500], $this->totals('2026-03-01', '2026-03-31', 'cash'));
        $this->assertSame(['revenue' => 6000, 'expenses' => 4000, 'net_result' => 2000], $this->totals('2026-04-01', '2026-04-30', 'cash'));
        $this->assertSame($this->totals('2026-03-01', '2026-04-30', 'accrual'), $this->totals('2026-03-01', '2026-04-30', 'cash'));
    }

    public function testTrialBalanceAndBalanceSheetBalance(): void
    {
        $this->dataset();

        $tb = $this->uc()->trialBalance()->execute($this->entity, $this->date('2026-03-01'), $this->date('2026-03-31'));
        $this->assertTrue($tb->balanced);
        $this->assertSame([], $tb->divergences);
        $this->assertSame(105500, array_column($tb->rows, 'closing', 'code')['1.1.01']);

        $this->assertSame(0, $this->uc()->statements()->balanceSheet($this->entity, $this->date('2026-03-31'))->totals['difference']);
        $this->assertSame(0, $this->uc()->statements()->balanceSheet($this->entity, $this->date('2026-04-30'))->totals['difference']);
    }

    public function testLedgerLastBalanceEqualsTheTrialBalanceClosing(): void
    {
        $this->dataset();

        $ledger = $this->uc()->generalLedger()->forAccount($this->entity, $this->ids['1.1.01'], $this->date('2026-03-01'), $this->date('2026-04-30'));
        $tb     = $this->uc()->trialBalance()->execute($this->entity, $this->date('2026-03-01'), $this->date('2026-04-30'));

        $rows = $ledger->sections[0]['rows'];

        $this->assertSame(array_column($tb->rows, 'closing', 'code')['1.1.01'], $rows[array_key_last($rows)]['balance']);
    }

    public function testGroupAccountEqualsTheSumOfItsChildren(): void
    {
        $this->dataset();

        $rows = array_column($this->uc()->trialBalance()->execute($this->entity, $this->date('2026-03-01'), $this->date('2026-04-30'))->rows, null, 'code');

        $this->assertSame($rows['1.1.01']['closing'] + $rows['1.1.02']['closing'], $rows['1']['closing']);
        $this->assertSame($rows['5.1.01']['debits'] + $rows['5.1.02']['debits'], $rows['5']['debits']);
    }

    public function testPaginatedLedgerMatchesTheFullLedger(): void
    {
        $this->dataset();

        $cash = $this->ids['1.1.01'];
        $full = $this->uc()->generalLedger()->forAccount($this->entity, $cash, null, null, null, 1000)->sections[0]['rows'];

        $paged  = [];
        $cursor = null;

        do {
            $page   = $this->uc()->generalLedger()->forAccount($this->entity, $cash, null, null, $cursor, 2);
            $paged  = [...$paged, ...$page->sections[0]['rows']];
            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        $this->assertSame($full, $paged);
    }

    public function testJournalAndSourceStatement(): void
    {
        $this->dataset();
        $this->uc()->createEntry()->execute($this->entity, $this->data('2026-04-20', 'Invoice', [['1.1.02', 'D', 5], ['4.1.01', 'C', 5]], null, null, 'invoice', '5'), true);

        $journal = $this->uc()->entryListing()->journal($this->entity, $this->date('2026-03-01'), $this->date('2026-03-31'));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($journal->entries, 'number'));

        $source = $this->uc()->entryListing()->bySource($this->entity, 'invoice', '5');
        $this->assertCount(1, $source->entries);
        $this->assertSame('4.1.01 Sales', $source->entries[0]['lines'][1]['account_code'] . ' ' . $source->entries[0]['lines'][1]['account_name']);
    }

    public function testYearClosingEndToEnd(): void
    {
        $this->dataset();

        foreach (range(1, 11) as $month) {
            $this->uc()->managePeriod()->close($this->entity, $this->uc()->listPeriods()->execute($this->entity, 1)[$month - 1]->id);
        }

        [$year, $closing] = $this->uc()->manageFiscalYear()->close($this->entity, 1, $this->ids['3.2.01']);

        $this->assertSame('CLOSED', $year->status->value);
        $this->assertNotNull($closing);

        $sheet = $this->uc()->statements()->balanceSheet($this->entity, $this->date('2026-12-31'));
        $this->assertSame(0, $sheet->totals['current_result']);
        $this->assertSame(0, $sheet->totals['difference']);

        $reopened = $this->uc()->manageFiscalYear()->reopen($this->entity, 1, 'Audit');
        $this->assertSame('OPEN', $reopened->status->value);
        $this->assertSame(7500, $this->uc()->statements()->balanceSheet($this->entity, $this->date('2026-12-31'))->totals['current_result']);
        $this->assertTrue($this->uc()->integrity()->verify($this->entity)->isIntact());
    }

    public function testTenThousandEntriesPaginateWithinTheTarget(): void
    {
        $year = 1;
        $cash = $this->ids['1.1.01'];
        $sale = $this->ids['4.1.01'];

        $entries = [];
        $lines   = [];

        for ($i = 1; $i <= 10000; $i++) {
            $date      = sprintf('2026-%02d-%02d', intdiv($i - 1, 900) + 1, ($i % 28) + 1);
            $entries[] = [
                'id'          => $i, 'entity_id' => $this->entity, 'fiscal_year_id' => $year, 'number' => $i, 'accrual_date' => $date,
                'recorded_at' => '2026-01-01 00:00:00', 'description' => 'Bulk ' . $i, 'status' => 'POSTED', 'chain_position' => $i,
            ];
            $lines[] = ['entry_id' => $i, 'account_id' => $cash, 'side' => 'D', 'amount' => 100, 'position' => 1];
            $lines[] = ['entry_id' => $i, 'account_id' => $sale, 'side' => 'C', 'amount' => 100, 'position' => 2];
        }

        foreach (array_chunk($entries, 500) as $chunk) {
            $this->db->table('acc_entries')->insertBatch($chunk);
        }

        foreach (array_chunk($lines, 500) as $chunk) {
            $this->db->table('acc_entry_lines')->insertBatch($chunk);
        }

        $builder = $this->uc()->generalLedger();

        $started = microtime(true);
        $first   = $builder->forAccount($this->entity, $cash, null, null, null, 100);
        $this->assertLessThan(2.0, microtime(true) - $started, 'first page');

        // Walk to the 90th page: each page re-derives its running balance from the cursor.
        $page = $first;

        for ($n = 0; $n < 89 && $page->nextCursor !== null; $n++) {
            $page = $builder->forAccount($this->entity, $cash, null, null, $page->nextCursor, 100);
        }

        $started = microtime(true);
        $deep    = $builder->forAccount($this->entity, $cash, null, null, $page->nextCursor, 100);
        $this->assertLessThan(2.0, microtime(true) - $started, 'a deep page');
        $this->assertSame(100 * 9000 + 100 * 100, $deep->sections[0]['rows'][99]['balance']);

        $started = microtime(true);
        $tb      = $this->uc()->trialBalance()->execute($this->entity, $this->date('2026-01-01'), $this->date('2026-12-31'));
        $this->assertLessThan(2.0, microtime(true) - $started, 'trial balance');
        $this->assertTrue($tb->balanced);
        $this->assertSame(1000000, $tb->totals['debits']);
    }
}
