<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

/**
 * Release gate. Runs inside an EMPTY CodeIgniter 4 application that installed
 * the package through Composer and ran `contabil:install`, with no host
 * adapter registered: every port is the package default.
 *
 * It creates a chart, posts, reverses, closes a period and reads the General
 * Ledger and the Trial Balance. Any failure exits non-zero.
 *
 * The CI job copies this file to `app/Commands/IndependenceCheck.php`.
 */
class IndependenceCheck extends BaseCommand
{
    protected $group       = 'Contabil';
    protected $name        = 'contabil:independence-check';
    protected $description = 'Exercises the whole package in an empty application (release gate).';

    public function run(array $params)
    {
        try {
            $this->check();
        } catch (Throwable $e) {
            CLI::error('INDEPENDENCE FAILED: ' . $e->getMessage());
            CLI::error($e->getTraceAsString());

            return EXIT_ERROR;
        }

        CLI::write('INDEPENDENCE OK', 'green');

        return EXIT_SUCCESS;
    }

    private function check(): void
    {
        $uc = service('contabil');

        $entity = $uc->createEntity()->execute('Independence Ltd');
        $this->expect($entity > 0, 'entity created');

        command('contabil:seed-chart --model generic --entity ' . $entity);

        $ids = [];

        foreach ($uc->listAccounts()->execute($entity) as $account) {
            $ids[$account->code] = (int) $account->id;
        }

        $this->expect(isset($ids['1.1.02'], $ids['3.1.01'], $ids['4.1.01'], $ids['5.2.02'], $ids['1.1.03']), 'chart seeded');

        $uc->createFiscalYear()->execute($entity, '2026-01-01');

        $entry = static fn (string $date, string $description, array $lines, ?string $cash = null): EntryData => new EntryData(
            $date,
            $description,
            array_map(static fn (array $l): array => ['account_id' => $ids[$l[0]], 'side' => $l[1], 'amount' => $l[2]], $lines),
            $cash,
        );

        $capital = $uc->createEntry()->execute($entity, $entry('2026-01-02', 'Capital', [['1.1.02', 'D', 500000], ['3.1.01', 'C', 500000]], '2026-01-02'), true)->entry;
        $sale    = $uc->createEntry()->execute($entity, $entry('2026-01-10', 'Sale', [['1.1.03', 'D', 120000], ['4.1.01', 'C', 120000]]), true)->entry;
        $uc->createEntry()->execute($entity, $entry('2026-01-15', 'Rent', [['5.2.02', 'D', 30000], ['1.1.02', 'C', 30000]], '2026-01-15'), true);

        $this->expect($capital->number === 1 && $sale->number === 2, 'gap-free numbers');

        $reversal = $uc->reverseEntry()->execute($entity, (int) $sale->id, 'Customer cancelled')->reversal;
        $this->expect($reversal->reversalOfId === $sale->id, 'sale reversed');

        $period = $uc->listPeriods()->execute($entity, 1)[0];
        $uc->managePeriod()->close($entity, (int) $period->id);

        try {
            $uc->createEntry()->execute($entity, $entry('2026-01-20', 'Late', [['1.1.02', 'D', 1], ['3.1.01', 'C', 1]]), true);
            $this->expect(false, 'a closed period must refuse entries');
        } catch (ConflictException) {
            // Expected.
        }

        $from = new DateTimeImmutable('2026-01-01');
        $to   = new DateTimeImmutable('2026-01-31');

        $tb = $uc->trialBalance()->execute($entity, $from, $to);
        $this->expect($tb->balanced, 'trial balance balances');
        $this->expect($tb->divergences === [], 'balance cache agrees with the ledger');

        $ledger = $uc->generalLedger()->forAccount($entity, $ids['1.1.02'], $from, $to);
        $rows   = $ledger->sections[0]['rows'];
        $this->expect($rows[array_key_last($rows)]['balance'] === array_column($tb->rows, 'closing', 'code')['1.1.02'], 'ledger ends on the trial balance closing');

        foreach (['accrual', 'cash'] as $basis) {
            $statement = $uc->statements()->incomeStatement($entity, $from, $to, $basis);
            CLI::write(sprintf('Income statement (%s): net result %d', $basis, $statement->totals['net_result']));
        }

        $this->expect($uc->integrity()->verify($entity)->isIntact(), 'hash chain intact');

        $sheet = $uc->statements()->balanceSheet($entity, $to);
        $this->expect($sheet->totals['difference'] === 0, 'balance sheet balances');

        CLI::write(sprintf('Ledger rows: %d, trial balance rows: %d', count($rows), count($tb->rows)));
    }

    private function expect(bool $condition, string $what): void
    {
        if (! $condition) {
            throw new RuntimeException('Expectation failed: ' . $what);
        }

        CLI::write('  ok  ' . $what);
    }
}
