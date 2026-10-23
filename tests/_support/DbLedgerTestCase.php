<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\UseCases;
use Config\Services;

/**
 * Database test with a ready entity, chart and 2026 calendar, working through
 * the same use cases a host or the API would call.
 */
abstract class DbLedgerTestCase extends DatabaseTestCase
{
    protected int $entity;

    /**
     * @var array<string, int>
     */
    protected array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->entity = $this->uc()->createEntity()->execute('Acme');
        $this->uc()->createFiscalYear()->execute($this->entity, '2026-01-01');

        $chart = [
            ['1', 'Assets', 'ASSET', null, false, false],
            ['1.1.01', 'Cash', 'ASSET', '1', false, true],
            ['1.1.02', 'Receivables', 'ASSET', '1', false, false],
            ['2', 'Liabilities', 'LIABILITY', null, false, false],
            ['2.1.01', 'Payables', 'LIABILITY', '2', false, false],
            ['3', 'Equity', 'EQUITY', null, false, false],
            ['3.1.01', 'Capital', 'EQUITY', '3', false, false],
            ['3.2.01', 'Retained result', 'EQUITY', '3', false, false],
            ['4', 'Revenue', 'REVENUE', null, false, false],
            ['4.1.01', 'Sales', 'REVENUE', '4', false, false],
            ['4.9.01', 'Sales returns', 'REVENUE', '4', true, false],
            ['5', 'Expenses', 'EXPENSE', null, false, false],
            ['5.1.01', 'Rent', 'EXPENSE', '5', false, false],
            ['5.1.02', 'Supplies', 'EXPENSE', '5', false, false],
        ];

        foreach ($chart as [$code, $name, $type, $parent, $contra, $cash]) {
            $this->ids[$code] = (int) $this->uc()->createAccount()->execute($this->entity, $code, $name, $type, $parent === null ? null : $this->ids[$parent], $contra, null, $cash)->id;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        Services::resetSingle('contabil');
    }

    protected function uc(): UseCases
    {
        return service('contabil');
    }

    /**
     * @param list<array{string, string, int}> $lines Account code, D or C, amount.
     */
    protected function data(string $date, string $description, array $lines, ?string $cashDate = null, ?string $key = null, ?string $sourceType = null, ?string $sourceId = null): EntryData
    {
        return new EntryData(
            $date,
            $description,
            array_map(fn (array $l): array => ['account_id' => $this->ids[$l[0]], 'side' => $l[1], 'amount' => $l[2]], $lines),
            $cashDate,
            $sourceType,
            $sourceId,
            $key,
        );
    }

    /**
     * @param list<array{string, string, int}> $lines
     */
    protected function posted(string $date, string $description, array $lines, ?string $cashDate = null, ?string $key = null): JournalEntry
    {
        return $this->uc()->createEntry()->execute($this->entity, $this->data($date, $description, $lines, $cashDate, $key), true)->entry;
    }
}
