<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Reports;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Reports\DimensionFilter;
use CognitioTechny\Contabil\Application\Reports\Statement;
use DateTimeImmutable;
use Tests\Support\LedgerKit;

/**
 * Reports restricted to an analytic dimension (cost center).
 *
 * March 2026, entity 1, lawsuits A (processo 1) and B (processo 2):
 *
 *  #1 Mar 01  Capital              Dr Cash 50,000          Cr Capital 50,000       (no dimension)
 *  #2 Mar 05  Fee A, cash          Dr Cash 8,000           Cr Sales 8,000          (cash; both lines processo 1, cliente 9)
 *  #3 Mar 06  Court cost A, cash   Dr Supplies 1,000       Cr Cash 1,000           (cash; both lines processo 1)
 *  #4 Mar 10  Fee B, on credit     Dr Receivables 6,000    Cr Sales 6,000          (both lines processo 2, cliente 9)
 *  #5 Mar 20  Collection B (half)  Dr Cash 3,000           Cr Receivables 3,000    (cash) settles #4 by 3,000
 *  #6 Mar 25  Rent                 Dr Rent 2,000           Cr Cash 2,000           (cash; no dimension)
 *
 * @internal
 */
final class DimensionReportsTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $kit = $this->kit = (new LedgerKit())->standard();
        $a   = ['processo' => '1', 'cliente' => '9'];
        $b   = ['processo' => '2', 'cliente' => '9'];

        $kit->posted('2026-03-01', 'Capital', [['1.1.01', 'D', 50000], ['3.1.01', 'C', 50000]], '2026-03-01');
        $kit->posted('2026-03-05', 'Fee A', [['1.1.01', 'D', 8000, $a], ['4.1.01', 'C', 8000, $a]], '2026-03-05');
        $kit->posted('2026-03-06', 'Court cost A', [['5.1.02', 'D', 1000, ['processo' => '1']], ['1.1.01', 'C', 1000, ['processo' => '1']]], '2026-03-06');
        $four = $kit->posted('2026-03-10', 'Fee B', [['1.1.02', 'D', 6000, $b], ['4.1.01', 'C', 6000, $b]]);
        $five = $kit->posted('2026-03-20', 'Collection B', [['1.1.01', 'D', 3000], ['1.1.02', 'C', 3000]], '2026-03-20');
        $kit->posted('2026-03-25', 'Rent', [['5.1.01', 'D', 2000], ['1.1.01', 'C', 2000]], '2026-03-25');

        $kit->settle->execute(LedgerKit::ENTITY, (int) $five->id, (int) $four->id, 3000);
    }

    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    /**
     * @return array<string, int>
     */
    private function totals(Statement $statement): array
    {
        return array_filter($statement->totals, 'is_int');
    }

    private function march(?DimensionFilter $dimension, string $basis = 'accrual'): Statement
    {
        return $this->kit->statements->incomeStatement(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), $basis, false, $dimension);
    }

    public function testDimensionsAreStoredAndReturned(): void
    {
        $entries = $this->kit->listing->journal(LedgerKit::ENTITY, $this->date('2026-03-05'), $this->date('2026-03-05'))->toArray();
        $lines   = $entries['entries'][0]['lines'] ?? [];

        $this->assertSame(['cliente' => '9', 'processo' => '1'], $lines[0]['dimensions'] ?? null);
    }

    public function testIncomeStatementWithoutFilterIsUnchanged(): void
    {
        $this->assertSame(['revenue' => 14000, 'expenses' => 3000, 'net_result' => 11000], $this->totals($this->march(null)));
    }

    public function testIncomeStatementOfOneLawsuit(): void
    {
        $this->assertSame(['revenue' => 8000, 'expenses' => 1000, 'net_result' => 7000], $this->totals($this->march(new DimensionFilter('processo', '1'))));
        $this->assertSame(['revenue' => 6000, 'expenses' => 0, 'net_result' => 6000], $this->totals($this->march(new DimensionFilter('processo', '2'))));
    }

    public function testIncomeStatementOfEveryTaggedLine(): void
    {
        // Rent has no dimension, so it stays out.
        $this->assertSame(['revenue' => 14000, 'expenses' => 1000, 'net_result' => 13000], $this->totals($this->march(new DimensionFilter('processo'))));
        $this->assertSame(['revenue' => 14000, 'expenses' => 0, 'net_result' => 14000], $this->totals($this->march(new DimensionFilter('cliente', '9'))));
    }

    public function testIncomeStatementOfOneLawsuitOnTheCashBasis(): void
    {
        // Half of fee B was collected: the settlement recognizes 3,000 of its tagged revenue line.
        $this->assertSame(['revenue' => 3000, 'expenses' => 0, 'net_result' => 3000], $this->totals($this->march(new DimensionFilter('processo', '2'), 'cash')));
        $this->assertSame(['revenue' => 8000, 'expenses' => 1000, 'net_result' => 7000], $this->totals($this->march(new DimensionFilter('processo', '1'), 'cash')));
    }

    public function testStatementCarriesTheFilter(): void
    {
        $statement = $this->march(new DimensionFilter('processo', '1'))->toArray();

        $this->assertSame(['dimension_type' => 'processo', 'dimension_id' => '1'], $statement['dimension']);
        $this->assertArrayNotHasKey('dimension', $this->march(null)->toArray());
    }

    public function testTrialBalanceOfOneLawsuitBalances(): void
    {
        $tb = $this->kit->trialBalance->execute(LedgerKit::ENTITY, $this->date('2026-03-01'), $this->date('2026-03-31'), false, new DimensionFilter('processo', '1'));

        // Both lines of each partida carry the dimension, so the filtered balance still closes.
        $this->assertTrue($tb->balanced);
        $this->assertNull($tb->divergences);
        $this->assertSame(9000, $tb->totals['debits']);
        $this->assertSame(9000, $tb->totals['credits']);

        $cash = array_values(array_filter($tb->rows, fn (array $r): bool => $r['account_id'] === $this->kit->ids['1.1.01']))[0];
        $this->assertSame(7000, $cash['closing']);
    }

    public function testLedgerOfOneLawsuit(): void
    {
        $cash   = $this->kit->ids['1.1.01'];
        $ledger = $this->kit->generalLedger->forAccount(LedgerKit::ENTITY, $cash, $this->date('2026-03-01'), $this->date('2026-03-31'), null, 100, new DimensionFilter('processo', '1'));

        $section = $ledger->sections[0];
        $this->assertCount(2, $section['rows']);
        $this->assertSame(8000, $section['debits']);
        $this->assertSame(1000, $section['credits']);
        $this->assertSame(7000, $section['closing']);
        $this->assertSame(['dimension_type' => 'processo', 'dimension_id' => '1'], $ledger->toArray()['dimension']);
    }

    public function testLedgerRangeOfOneLawsuit(): void
    {
        $ledger = $this->kit->generalLedger->forRange(LedgerKit::ENTITY, null, null, $this->date('2026-03-01'), $this->date('2026-03-31'), new DimensionFilter('processo', '2'));

        $codes = array_map(static fn (array $s): string => $s['account']['code'], $ledger->sections);
        $this->assertSame(['1.1.02', '4.1.01'], $codes);
    }

    public function testReversalKeepsTheDimensions(): void
    {
        $entry    = $this->kit->posted('2026-03-28', 'Wrong fee', [['1.1.01', 'D', 500, ['processo' => '1']], ['4.1.01', 'C', 500, ['processo' => '1']]], '2026-03-28');
        $reversal = $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $entry->id, 'Typo', '2026-03-29');

        $this->assertSame(['processo' => '1'], $reversal->reversal->lines[0]->dimensions);
        $this->assertSame(['revenue' => 8000, 'expenses' => 1000, 'net_result' => 7000], $this->totals($this->march(new DimensionFilter('processo', '1'))));
    }
}
