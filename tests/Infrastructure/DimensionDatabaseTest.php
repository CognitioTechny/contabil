<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CognitioTechny\Contabil\Application\Reports\DimensionFilter;
use DateTimeImmutable;
use Tests\Support\DbLedgerTestCase;

/**
 * Analytic dimensions against the real tables and queries.
 *
 * @internal
 */
final class DimensionDatabaseTest extends DbLedgerTestCase
{
    private function date(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date);
    }

    private function dataset(): void
    {
        $a = ['processo' => '1', 'cliente' => '9'];
        $b = ['processo' => '2', 'cliente' => '9'];

        $this->posted('2026-03-01', 'Capital', [['1.1.01', 'D', 50000], ['3.1.01', 'C', 50000]], '2026-03-01');
        $this->posted('2026-03-05', 'Fee A', [['1.1.01', 'D', 8000, $a], ['4.1.01', 'C', 8000, $a]], '2026-03-05');
        $this->posted('2026-03-06', 'Court cost A', [['5.1.02', 'D', 1000, ['processo' => '1']], ['1.1.01', 'C', 1000, ['processo' => '1']]], '2026-03-06');
        $four = $this->posted('2026-03-10', 'Fee B', [['1.1.02', 'D', 6000, $b], ['4.1.01', 'C', 6000, $b]]);
        $five = $this->posted('2026-03-20', 'Collection B', [['1.1.01', 'D', 3000], ['1.1.02', 'C', 3000]], '2026-03-20');
        $this->posted('2026-03-25', 'Rent', [['5.1.01', 'D', 2000], ['1.1.01', 'C', 2000]], '2026-03-25');

        $this->uc()->settleEntry()->execute($this->entity, (int) $five->id, (int) $four->id, 3000);
    }

    /**
     * @return array<string, int>
     */
    private function totals(?DimensionFilter $dimension, string $basis = 'accrual'): array
    {
        return array_filter($this->uc()->statements()->incomeStatement($this->entity, $this->date('2026-03-01'), $this->date('2026-03-31'), $basis, false, $dimension)->totals, 'is_int');
    }

    public function testDimensionsArePersistedAndReloaded(): void
    {
        $entry = $this->posted('2026-03-05', 'Fee', [['1.1.01', 'D', 800, ['processo' => '42', 'caso' => '7']], ['4.1.01', 'C', 800]], '2026-03-05');

        $this->seeInDatabase('acc_entry_line_dimensions', ['entry_id' => $entry->id, 'dimension_type' => 'processo', 'dimension_id' => '42']);
        $this->seeNumRecords(2, 'acc_entry_line_dimensions', ['entry_id' => $entry->id]);

        $reloaded = $this->uc()->entries()->get($this->entity, (int) $entry->id);

        $this->assertSame(['caso' => '7', 'processo' => '42'], $reloaded->lines[0]->dimensions);
        $this->assertSame([], $reloaded->lines[1]->dimensions);
    }

    public function testChainStaysIntactWithDimensions(): void
    {
        $this->dataset();

        $this->assertTrue($this->uc()->integrity()->verify($this->entity)->isIntact());
    }

    public function testDraftChangesReplaceTheDimensions(): void
    {
        $draft = $this->uc()->createEntry()->execute($this->entity, $this->data('2026-03-05', 'Fee', [['1.1.01', 'D', 800, ['processo' => '1']], ['4.1.01', 'C', 800]]))->entry;

        $this->uc()->manageDraft()->update($this->entity, (int) $draft->id, $this->data('2026-03-05', 'Fee', [['1.1.01', 'D', 800, ['processo' => '2']], ['4.1.01', 'C', 800, ['processo' => '2']]]));

        $this->seeNumRecords(2, 'acc_entry_line_dimensions', ['entry_id' => $draft->id, 'dimension_id' => '2']);
        $this->seeNumRecords(0, 'acc_entry_line_dimensions', ['entry_id' => $draft->id, 'dimension_id' => '1']);

        $this->uc()->manageDraft()->delete($this->entity, (int) $draft->id);

        $this->seeNumRecords(0, 'acc_entry_line_dimensions', ['entry_id' => $draft->id]);
    }

    public function testIncomeStatementByDimension(): void
    {
        $this->dataset();

        $this->assertSame(['revenue' => 14000, 'expenses' => 3000, 'net_result' => 11000], $this->totals(null));
        $this->assertSame(['revenue' => 8000, 'expenses' => 1000, 'net_result' => 7000], $this->totals(new DimensionFilter('processo', '1')));
        $this->assertSame(['revenue' => 6000, 'expenses' => 0, 'net_result' => 6000], $this->totals(new DimensionFilter('processo', '2')));
        $this->assertSame(['revenue' => 14000, 'expenses' => 1000, 'net_result' => 13000], $this->totals(new DimensionFilter('processo')));
        $this->assertSame(['revenue' => 3000, 'expenses' => 0, 'net_result' => 3000], $this->totals(new DimensionFilter('processo', '2'), 'cash'));
    }

    public function testTrialBalanceAndLedgerByDimension(): void
    {
        $this->dataset();

        $filter = new DimensionFilter('processo', '1');
        $tb     = $this->uc()->trialBalance()->execute($this->entity, $this->date('2026-03-01'), $this->date('2026-03-31'), false, $filter);

        $this->assertTrue($tb->balanced);
        $this->assertNull($tb->divergences);
        $this->assertSame(7000, array_column($tb->rows, 'closing', 'code')['1.1.01']);

        $ledger = $this->uc()->generalLedger()->forAccount($this->entity, $this->ids['1.1.01'], $this->date('2026-03-01'), $this->date('2026-03-31'), null, 100, $filter);
        $this->assertCount(2, $ledger->sections[0]['rows']);
        $this->assertSame(7000, $ledger->sections[0]['closing']);
    }

    public function testReportsApiAcceptsTheDimensionFilter(): void
    {
        $this->dataset();

        $statement = $this->uc()->statements()->incomeStatement($this->entity, $this->date('2026-03-01'), $this->date('2026-03-31'), 'accrual', false, DimensionFilter::fromInput('processo', '1'));

        $this->assertSame(['dimension_type' => 'processo', 'dimension_id' => '1'], $statement->toArray()['dimension']);
    }
}
