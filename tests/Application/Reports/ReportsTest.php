<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Reports;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Defaults\NullPdfRenderer;
use CognitioTechny\Contabil\Application\Reports\ReportRenderer;
use CognitioTechny\Contabil\Application\Reports\Statement;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use Tests\Support\LedgerKit;

/**
 * Books and statements checked by hand against one reference dataset.
 *
 * March 2026 and April 2026, entity 1:
 *
 *  #1 Mar 01  Capital                Dr Cash 100,000        Cr Capital 100,000      (cash 03-01)
 *  #2 Mar 05  Credit sale            Dr Receivables 10,000  Cr Sales 10,000
 *  #3 Mar 10  Cash sale              Dr Cash 3,000          Cr Sales 3,000          (cash 03-10)
 *  #4 Mar 15  Credit purchase        Dr Supplies 4,000      Cr Payables 4,000
 *  #5 Mar 18  Wrong cash sale        Dr Cash 800            Cr Sales 800            (cash 03-18)
 *  #6 Mar 19  Reversal of #5         (mirror)                                       (cash 03-19)
 *  #7 Mar 20  Rent paid              Dr Rent 1,500          Cr Cash 1,500           (cash 03-20)
 *  #8 Mar 25  Collection (part)      Dr Cash 4,000          Cr Receivables 4,000    (cash 03-25) settles #2 by 4,000
 *  #9 Apr 10  Collection (rest)      Dr Cash 6,000          Cr Receivables 6,000    (cash 04-10) settles #2 by 6,000
 * #10 Apr 12  Supplier paid          Dr Payables 4,000      Cr Cash 4,000           (cash 04-12) settles #4 by 4,000
 *
 * @internal
 */
final class ReportsTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $kit = $this->kit = (new LedgerKit())->standard();

        $kit->posted('2026-03-01', 'Capital', [['1.1.01', 'D', 100000], ['3.1.01', 'C', 100000]], '2026-03-01');
        $two = $kit->posted('2026-03-05', 'Credit sale', [['1.1.02', 'D', 10000], ['4.1.01', 'C', 10000]]);
        $kit->posted('2026-03-10', 'Cash sale', [['1.1.01', 'D', 3000], ['4.1.01', 'C', 3000]], '2026-03-10');
        $four = $kit->posted('2026-03-15', 'Credit purchase', [['5.1.02', 'D', 4000], ['2.1.01', 'C', 4000]]);
        $five = $kit->posted('2026-03-18', 'Wrong cash sale', [['1.1.01', 'D', 800], ['4.1.01', 'C', 800]], '2026-03-18');
        $kit->reverse->execute(LedgerKit::ENTITY, (int) $five->id, 'Wrong customer', '2026-03-19');
        $kit->posted('2026-03-20', 'Rent paid', [['5.1.01', 'D', 1500], ['1.1.01', 'C', 1500]], '2026-03-20');
        $eight = $kit->posted('2026-03-25', 'Collection part', [['1.1.01', 'D', 4000], ['1.1.02', 'C', 4000]], '2026-03-25');
        $nine  = $kit->posted('2026-04-10', 'Collection rest', [['1.1.01', 'D', 6000], ['1.1.02', 'C', 6000]], '2026-04-10');
        $ten   = $kit->posted('2026-04-12', 'Supplier paid', [['2.1.01', 'D', 4000], ['1.1.01', 'C', 4000]], '2026-04-12');

        $kit->settle->execute(LedgerKit::ENTITY, (int) $eight->id, (int) $two->id, 4000);
        $kit->settle->execute(LedgerKit::ENTITY, (int) $nine->id, (int) $two->id, 6000);
        $kit->settle->execute(LedgerKit::ENTITY, (int) $ten->id, (int) $four->id, 4000);
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /**
     * @return array<string, int> Section total by name.
     */
    private function totals(Statement $statement): array
    {
        return array_filter($statement->totals, 'is_int');
    }

    public function testIncomeStatementOnTheAccrualBasis(): void
    {
        $march = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'accrual');

        // Sales 10,000 + 3,000 + 800 - 800 reversed = 13,000; expenses 4,000 + 1,500 = 5,500.
        $this->assertSame(['revenue' => 13000, 'expenses' => 5500, 'net_result' => 7500], $this->totals($march));

        $april = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-04-30'), 'accrual');
        $this->assertSame(['revenue' => 0, 'expenses' => 0, 'net_result' => 0], $this->totals($april));
    }

    public function testIncomeStatementOnTheCashBasis(): void
    {
        $march = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'cash');

        // Direct cash sale 3,000 (+800 -800) and 40% of the credit sale (4,000 of 10,000) = 7,000 revenue.
        // Rent 1,500 paid directly; the 4,000 purchase is not paid until April.
        $this->assertSame(['revenue' => 7000, 'expenses' => 1500, 'net_result' => 5500], $this->totals($march));

        $april = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-04-30'), 'cash');
        $this->assertSame(['revenue' => 6000, 'expenses' => 4000, 'net_result' => 2000], $this->totals($april));
    }

    public function testBothBasesAgreeOnceEverythingIsSettled(): void
    {
        $accrual = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-04-30'), 'accrual');
        $cash    = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-04-30'), 'cash');

        $this->assertSame($this->totals($accrual), $this->totals($cash));
    }

    public function testBasisFallsBackToTheEntityDefault(): void
    {
        $this->kit->chart->store->setBasis(LedgerKit::ENTITY, 'CASH');

        $statement = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'));

        $this->assertSame('CASH', $statement->toArray()['basis']);
        $this->assertSame(5500, $statement->totals['net_result']);

        $override = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'accrual');
        $this->assertSame(7500, $override->totals['net_result']);
    }

    public function testUnknownBasisIsRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'mixed');
    }

    public function testContraAccountsReduceTheirSection(): void
    {
        $this->kit->posted('2026-04-15', 'Return', [['4.9.01', 'D', 700], ['1.1.01', 'C', 700]]);

        $april = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-04-30'), 'accrual');

        $this->assertSame(-700, $april->totals['revenue']);
        $returns = $april->sections['revenue'][0]['children'][0];
        $this->assertSame('4.9.01', $returns['code']);
        $this->assertSame(-700, $returns['amount']);
        $this->assertTrue($returns['contra']);
    }

    public function testStatementStructureComesFromTheChart(): void
    {
        $statement = $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'accrual');

        $revenue = $statement->sections['revenue'][0];

        $this->assertSame('4', $revenue['code']);
        $this->assertTrue($revenue['is_group']);
        $this->assertSame(array_sum(array_column($revenue['children'], 'amount')), $revenue['amount'], 'a group is the sum of its children');
        $this->assertSame(['5.1.01', '5.1.02'], array_column($statement->sections['expenses'][0]['children'], 'code'));
    }

    public function testBalanceSheetBalancesAndHonoursTheCurrentResult(): void
    {
        $sheet = $this->kit->statements->balanceSheet(LedgerKit::ENTITY, $this->date('2026-03-31'));

        // Cash 100,000 + 3,000 - 1,500 + 4,000 = 105,500; receivables 10,000 - 4,000 = 6,000.
        $this->assertSame(111500, $sheet->totals['assets']);
        $this->assertSame(4000, $sheet->totals['liabilities']);
        $this->assertSame(100000, $sheet->totals['equity']);
        $this->assertSame(7500, $sheet->totals['current_result']);
        $this->assertSame(0, $sheet->totals['difference']);
        $this->assertTrue($sheet->toArray()['balanced']);

        $april = $this->kit->statements->balanceSheet(LedgerKit::ENTITY, $this->date('2026-04-30'));
        $this->assertSame(0, $april->totals['difference']);
        $this->assertSame(107500, $april->totals['assets']);
    }

    public function testBalanceSheetBalancesAfterTheYearIsClosed(): void
    {
        $kit = (new LedgerKit('3.2.01'))->standard();
        $kit->posted('2026-01-05', 'Capital', [['1.1.01', 'D', 1000], ['3.1.01', 'C', 1000]]);
        $kit->posted('2026-02-05', 'Sale', [['1.1.01', 'D', 300], ['4.1.01', 'C', 300]]);

        foreach (range(1, 11) as $month) {
            $kit->periods->close(LedgerKit::ENTITY, (int) $kit->years->periodContaining(LedgerKit::ENTITY, $this->date(sprintf('2026-%02d-15', $month)))[1]->id);
        }

        $kit->fiscalYears->close(LedgerKit::ENTITY, (int) $kit->years->forEntity(LedgerKit::ENTITY)[0]->id);

        $sheet = $kit->statements->balanceSheet(LedgerKit::ENTITY, $this->date('2026-12-31'));

        $this->assertSame(0, $sheet->totals['current_result'], 'the result moved into equity');
        $this->assertSame(1300, $sheet->totals['equity']);
        $this->assertSame(0, $sheet->totals['difference']);
    }

    public function testBalanceSheetRefusesTheCashBasis(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        $this->kit->statements->balanceSheet(LedgerKit::ENTITY, $this->date('2026-03-31'), 'cash');
    }

    public function testTrialBalanceBalancesAndAddsUpGroups(): void
    {
        $tb = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'));

        $this->assertTrue($tb->balanced);
        $this->assertSame($tb->totals['debits'], $tb->totals['credits']);
        $this->assertSame($tb->totals['closing_debit'], $tb->totals['closing_credit']);
        $this->assertSame(0, $tb->totals['opening_debit']);

        $rows = array_column($tb->rows, null, 'code');

        $this->assertSame(105500, $rows['1.1.01']['closing']);
        $this->assertSame(6000, $rows['1.1.02']['closing']);
        $this->assertSame($rows['1.1.01']['closing'] + $rows['1.1.02']['closing'], $rows['1']['closing'], 'the asset group sums its children');
        $this->assertTrue($rows['1']['is_group']);
        $this->assertSame(-4000, $rows['2.1.01']['closing']);
        $this->assertNull($tb->divergences, 'the in-memory cache cannot answer');
    }

    public function testTrialBalanceCarriesTheOpeningBalance(): void
    {
        $april = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-04-30'));
        $rows  = array_column($april->rows, null, 'code');

        $this->assertSame(105500, $rows['1.1.01']['opening']);
        $this->assertSame(6000, $rows['1.1.01']['debits']);
        $this->assertSame(4000, $rows['1.1.01']['credits']);
        $this->assertSame(107500, $rows['1.1.01']['closing']);
        $this->assertTrue($april->balanced);
    }

    public function testTrialBalanceIsTheSameWhateverTheBasis(): void
    {
        $a = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-04-30'));

        $this->kit->chart->store->setBasis(LedgerKit::ENTITY, 'CASH');
        $b = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-04-30'));

        $this->assertSame($a->toArray(), $b->toArray());
    }

    public function testLedgerRunningBalanceEndsOnTheTrialBalanceClosing(): void
    {
        $cash   = $this->kit->ids['1.1.01'];
        $ledger = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $cash, $this->date('2026-03-01'), $this->date('2026-04-30'));
        $tb     = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-04-30'));

        $section = $ledger->sections[0];
        $rows    = $section['rows'];

        $this->assertSame(array_column($tb->rows, 'closing', 'code')['1.1.01'], $rows[array_key_last($rows)]['balance']);
        $this->assertSame($section['closing'], $rows[array_key_last($rows)]['balance']);
        $this->assertNull($ledger->nextCursor);
        $this->assertSame(0, $section['opening']);
    }

    public function testLedgerOfAGroupAggregatesItsChildren(): void
    {
        $group = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $this->kit->ids['1'], $this->date('2026-03-01'), $this->date('2026-03-31'));

        $accounts = array_unique(array_column($group->sections[0]['rows'], 'account_code'));
        sort($accounts);

        $this->assertSame(['1.1.01', '1.1.02'], $accounts);
        $this->assertSame(111500, $group->sections[0]['closing']);
    }

    public function testLedgerUsesTheAccountsOwnNature(): void
    {
        $payables = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $this->kit->ids['2.1.01'], $this->date('2026-03-01'), $this->date('2026-03-31'));

        $this->assertSame(4000, $payables->sections[0]['closing'], 'a credit-nature account is positive on the credit side');
    }

    public function testLedgerOpeningBalanceBeforeTheRange(): void
    {
        $april = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $this->kit->ids['1.1.01'], $this->date('2026-04-01'), $this->date('2026-04-30'));

        $this->assertSame(105500, $april->sections[0]['opening']);
        $this->assertSame(107500, $april->sections[0]['closing']);
        $this->assertSame([111500, 107500], array_column($april->sections[0]['rows'], 'balance'));
    }

    public function testLedgerPagesChainWithoutGapsOrRepeats(): void
    {
        $cash = $this->kit->ids['1.1.01'];
        $all  = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $cash, $this->date('2026-03-01'), $this->date('2026-04-30'), null, 1000)->sections[0]['rows'];

        $paged  = [];
        $cursor = null;
        $pages  = 0;

        do {
            $page   = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $cash, $this->date('2026-03-01'), $this->date('2026-04-30'), $cursor, 3);
            $paged  = [...$paged, ...$page->sections[0]['rows']];
            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($all, $paged, 'pagination returns the same rows with the same running balance');
        $this->assertGreaterThan(1, $pages);
    }

    public function testLedgerRejectsABadCursor(): void
    {
        $this->expectException(ValidationException::class);
        $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $this->kit->ids['1.1.01'], null, null, 'not-a-cursor');
    }

    public function testLedgerRangeGivesOneSectionPerAccount(): void
    {
        $range = $this->kit->generalLedger->forRange(LedgerKit::ENTITY, '1.1.01', '1.1.02', $this->date('2026-03-01'), $this->date('2026-03-31'));

        $this->assertSame(['1.1.01', '1.1.02'], array_map(static fn (array $s): string => $s['account']['code'], $range->sections));
    }

    public function testJournalListsEntriesChronologicallyWithTheirLines(): void
    {
        $journal = $this->kit->listing->journal(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'));

        $numbers = array_column($journal->entries, 'number');

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $numbers);
        $this->assertSame('1.1.01', $journal->entries[0]['lines'][0]['account_code']);
        $this->assertSame('REVERSED', $journal->entries[4]['status']);
        $this->assertSame($journal->entries[4]['id'], $journal->entries[5]['reversal_of_id']);
    }

    public function testJournalPagination(): void
    {
        $first  = $this->kit->listing->journal(LedgerKit::ENTITY, null, null, null, 4);
        $second = $this->kit->listing->journal(LedgerKit::ENTITY, null, null, $first->nextCursor, 4);
        $third  = $this->kit->listing->journal(LedgerKit::ENTITY, null, null, $second->nextCursor, 4);

        $this->assertSame([1, 2, 3, 4], array_column($first->entries, 'number'));
        $this->assertSame([5, 6, 7, 8], array_column($second->entries, 'number'));
        $this->assertSame([9, 10], array_column($third->entries, 'number'));
        $this->assertNull($third->nextCursor);
    }

    public function testStatementBySource(): void
    {
        $this->kit->posted('2026-04-20', 'Invoice 5', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]], null, null, 'invoice', '5');

        $statement = $this->kit->listing->bySource(LedgerKit::ENTITY, 'invoice', '5');

        $this->assertCount(1, $statement->entries);
        $this->assertSame(100, $statement->totalDebits);
        $this->assertCount(0, $this->kit->listing->bySource(LedgerKit::ENTITY, 'invoice', '404')->entries);
    }

    public function testDraftsNeverAppearInAnyReport(): void
    {
        $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-30', 'Draft', [['1.1.01', 'D', 999999], ['4.1.01', 'C', 999999]]));

        $tb = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'));

        $this->assertSame(105500, array_column($tb->rows, 'closing', 'code')['1.1.01']);
    }

    public function testCashBasisSplitsRoundingOntoTheLastSettlement(): void
    {
        $kit  = (new LedgerKit())->standard();
        $sale = $kit->posted('2026-03-02', 'Mixed sale', [['1.1.02', 'D', 10], ['4.1.01', 'C', 7], ['2.1.01', 'C', 3]]);

        foreach ([[5, '2026-03-10'], [5, '2026-04-10']] as [$amount, $date]) {
            $payment = $kit->posted($date, 'Collection', [['1.1.01', 'D', $amount], ['1.1.02', 'C', $amount]], $date);
            $kit->settle->execute(LedgerKit::ENTITY, (int) $payment->id, (int) $sale->id, $amount);
        }

        $march = $kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'cash');
        $april = $kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-04-30'), 'cash');

        $this->assertSame(4, $march->totals['revenue'], '7 x 5/10 = 3.5 rounds to 4');
        $this->assertSame(3, $april->totals['revenue'], 'the final settlement carries the residual');
    }

    public function testCashBasisIgnoresReversedSettlementEntries(): void
    {
        $kit  = (new LedgerKit())->standard();
        $sale = $kit->posted('2026-03-02', 'Sale', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
        $pay  = $kit->posted('2026-03-10', 'Collection', [['1.1.01', 'D', 100], ['1.1.02', 'C', 100]], '2026-03-10');
        $kit->settle->execute(LedgerKit::ENTITY, (int) $pay->id, (int) $sale->id, 100);

        $before = $kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'cash');
        $kit->reverse->execute(LedgerKit::ENTITY, (int) $pay->id, 'Cheque bounced');
        $after = $kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), 'cash');

        $this->assertSame(100, $before->totals['revenue']);
        $this->assertSame(0, $after->totals['revenue']);
    }

    public function testRenderersProduceTheSameNumbersInEveryFormat(): void
    {
        $renderer = new ReportRenderer();
        $tb       = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'));

        $json = json_decode($renderer->json($tb), true);
        $csv  = array_map('str_getcsv', explode("\r\n", trim($renderer->csv($tb))));
        $html = $renderer->html($tb);

        $this->assertSame(105500, array_column($json['data']['rows'], 'closing', 'code')['1.1.01']);

        $cashRow = array_values(array_filter($csv, static fn (array $row): bool => $row[0] === '1.1.01'))[0];
        $this->assertSame('1055.00', $cashRow[6], 'CSV amounts are decimal strings');
        $this->assertStringContainsString('1055.00', $html);
        $this->assertSame(count($tb->rows) + 2, count($csv), 'header, rows and a total line');
    }

    public function testCsvDefusesFormulasAndQuotesSpecialCharacters(): void
    {
        $this->kit->posted('2026-04-21', '=HYPERLINK("http://x")', [['1.1.02', 'D', 1], ['4.1.01', 'C', 1]], null, null, 'invoice', '9');

        $csv = (new ReportRenderer())->csv($this->kit->listing->bySource(LedgerKit::ENTITY, 'invoice', '9'));

        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://x\"\")\"", $csv);
    }

    public function testPdfNeedsARenderer(): void
    {
        $this->expectException(UnsupportedOperationException::class);
        (new ReportRenderer())->pdf($this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31')), new NullPdfRenderer());
    }

    public function testInvalidRangesAreRejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-04-01'), $this->date('2026-03-01'));
    }
}
