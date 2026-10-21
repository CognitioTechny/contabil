<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CognitioTechny\Contabil\Application\Chart\ChartFormat;
use CognitioTechny\Contabil\Application\Chart\ChartImportException;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\UseCases;
use Tests\Support\DatabaseTestCase;

/**
 * The chart and calendar use cases against a real database (SQLite in memory,
 * or MySQL through the `database.tests.*` variables).
 *
 * @internal
 */
final class ChartDatabaseTest extends DatabaseTestCase
{
    private UseCases $uc;
    private int $entity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uc     = service('contabil');
        $this->entity = $this->uc->createEntity()->execute('Acme Ltd', '12.345', 'eur');
    }

    public function testEntityIsStoredWithDefaults(): void
    {
        $row = $this->db->table('acc_entities')->where('id', $this->entity)->get()->getRowArray();

        $this->assertSame('Acme Ltd', $row['name']);
        $this->assertSame('EUR', $row['currency']);
        $this->assertSame('ACCRUAL', $row['default_basis']);
    }

    public function testAccountsRoundTripThroughTheDatabase(): void
    {
        $root = $this->uc->createAccount()->execute($this->entity, '1', 'Assets', 'asset');
        $bank = $this->uc->createAccount()->execute($this->entity, '1.1', 'Bank', null, $root->id, false, true, true, 'REF-1');

        $stored = $this->uc->getAccount()->execute($this->entity, (int) $bank->id);

        $this->assertEquals($bank, $stored);
        $this->assertTrue($stored->isCashAccount);
        $this->assertSame('DEBIT', $stored->nature->value);
        $this->assertFalse($this->uc->getAccount()->execute($this->entity, (int) $root->id)->acceptsEntries);
    }

    public function testCodeUniquenessAndEntityScoping(): void
    {
        $this->uc->createAccount()->execute($this->entity, '1', 'Assets', 'asset');
        $other = $this->uc->createEntity()->execute('Other');

        $this->uc->createAccount()->execute($other, '1', 'Assets', 'asset');
        $this->assertCount(1, $this->uc->listAccounts()->execute($other));

        $this->expectException(ConflictException::class);
        $this->uc->createAccount()->execute($this->entity, '1', 'Again', 'asset');
    }

    public function testMovementIsDetectedFromEntryLines(): void
    {
        $cash = $this->uc->createAccount()->execute($this->entity, '1', 'Cash', 'asset');
        $this->insertLine((int) $cash->id);

        try {
            $this->uc->updateAccount()->execute($this->entity, (int) $cash->id, ['type' => 'expense']);
            $this->fail('A type change after movement must be rejected.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        try {
            $this->uc->deleteAccount()->execute($this->entity, (int) $cash->id);
            $this->fail('Deleting an account with movement must be rejected.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        $this->assertFalse($this->uc->deactivateAccount()->execute($this->entity, (int) $cash->id)->active);
    }

    public function testMoveRewritesTheSubtreeLevels(): void
    {
        $uc     = $this->uc->createAccount();
        $assets = $uc->execute($this->entity, '1', 'Assets', 'asset');
        $a      = $uc->execute($this->entity, '1.1', 'A', null, $assets->id);
        $b      = $uc->execute($this->entity, '1.2', 'B', null, $assets->id);
        $leaf   = $uc->execute($this->entity, '1.1.1', 'Leaf', null, $a->id);

        $this->uc->moveAccount()->execute($this->entity, (int) $a->id, $b->id);

        $this->assertSame(4, $this->uc->getAccount()->execute($this->entity, (int) $leaf->id)->level);
        $this->assertSame($b->id, $this->uc->getAccount()->execute($this->entity, (int) $a->id)->parentId);
    }

    public function testImportIsAtomicOnTheDatabase(): void
    {
        $rows = [
            ['code' => '1', 'name' => 'Assets', 'type' => 'asset'],
            ['code' => '1.1', 'name' => 'Cash', 'parent_code' => '1'],
            ['code' => '2', 'name' => 'Broken', 'type' => 'nonsense'],
        ];

        try {
            $this->uc->importChart()->execute($this->entity, $rows);
            $this->fail('The import must be rejected.');
        } catch (ChartImportException $e) {
            $this->assertSame([3], array_column($e->errors(), 'row'));
        }

        $this->assertSame(0, $this->db->table('acc_accounts')->countAllResults(), 'The valid rows must be rolled back too.');
    }

    public function testExportThenImportIntoAnotherEntity(): void
    {
        $this->uc->importChart()->execute($this->entity, ChartFormat::parse((string) file_get_contents(dirname(__DIR__, 2) . '/resources/charts/generic.json'), 'json'));
        $other = $this->uc->createEntity()->execute('Copy');

        $csv = $this->uc->exportChart()->execute($this->entity, 'csv');
        $this->uc->importChart()->execute($other, ChartFormat::parse($csv, 'csv'));

        $this->assertSame(
            array_column($this->uc->exportChart()->rows($this->entity), 'code'),
            array_column($this->uc->exportChart()->rows($other), 'code'),
        );
    }

    public function testFiscalYearIsStoredWithTwelvePeriods(): void
    {
        [$year, $periods] = $this->uc->createFiscalYear()->execute($this->entity, '2026-01-01');

        $this->assertCount(12, $periods);
        $this->assertSame('2026-12-31', $periods[11]->endDate->format('Y-m-d'));
        $this->assertSame(12, $this->db->table('acc_periods')->where('fiscal_year_id', $year->id)->countAllResults());
        $this->assertSame([(int) $year->id], array_map(static fn ($y) => $y->id, $this->uc->listFiscalYears()->execute($this->entity)));
    }

    public function testOverlappingYearLeavesNoPeriodsBehind(): void
    {
        $this->uc->createFiscalYear()->execute($this->entity, '2026-01-01');

        try {
            $this->uc->createFiscalYear()->execute($this->entity, '2026-06-01');
            $this->fail('An overlapping year must be rejected.');
        } catch (ConflictException) {
            $this->assertSame(12, $this->db->table('acc_periods')->countAllResults());
        }
    }

    private function insertLine(int $accountId): void
    {
        $this->db->table('acc_entries')->insert([
            'entity_id' => $this->entity, 'accrual_date' => '2026-01-10', 'recorded_at' => '2026-01-10 10:00:00',
            'description' => 'x', 'status' => 'DRAFT',
        ]);
        $entryId = (int) $this->db->insertID();

        $this->db->table('acc_entry_lines')->insert([
            'entry_id' => $entryId, 'account_id' => $accountId, 'side' => 'D', 'amount' => 100, 'position' => 1,
        ]);
    }
}
