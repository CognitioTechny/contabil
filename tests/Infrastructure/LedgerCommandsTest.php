<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\Filters\CITestStreamFilter;
use Tests\Support\DbLedgerTestCase;

/**
 * @internal
 */
final class LedgerCommandsTest extends DbLedgerTestCase
{
    public function testVerifyIntegrityReportsAnIntactAndATamperedLedger(): void
    {
        $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
        $this->posted('2026-03-03', 'Two', [['1.1.02', 'D', 200], ['4.1.01', 'C', 200]]);

        $clean = $this->runCommand('contabil:verify-integrity --entity ' . $this->entity);
        $this->assertStringContainsString('2 entries checked', $clean);
        $this->assertStringNotContainsString('does not match', $clean);

        $this->db->table('acc_entries')->where('id', 1)->update(['description' => 'Changed by hand']);

        $broken = $this->runCommand('contabil:verify-integrity --entity ' . $this->entity);
        $this->assertStringContainsString('entry 1', $broken);
        $this->assertStringContainsString('changed after posting', $broken);
    }

    public function testVerifyIntegrityWithoutAnEntityChecksThemAll(): void
    {
        $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);

        $this->assertStringContainsString('Entity ' . $this->entity . ': 1 entries checked', $this->runCommand('contabil:verify-integrity'));
    }

    public function testRecalculateBalancesRebuildsTheCache(): void
    {
        $this->posted('2026-03-02', 'One', [['1.1.02', 'D', 100], ['4.1.01', 'C', 100]]);
        $this->db->table('acc_balances')->update(['closing_balance' => 12345]);

        $output = $this->runCommand('contabil:recalculate-balances --entity ' . $this->entity);

        $this->assertStringContainsString('2 balance rows written', $output);
        $this->assertSame(100, (int) $this->db->table('acc_balances')->where('account_id', $this->ids['1.1.02'])->get()->getRow()->closing_balance);
    }

    public function testOpenYearOpensTheFirstAndTheNextYears(): void
    {
        $other = $this->uc()->createEntity()->execute('Beta');

        $this->assertStringContainsString('pass --start', $this->runCommand('contabil:open-year --entity ' . $other));
        $this->assertStringContainsString('Fiscal year 2026 (2026-01-01 to 2026-12-31) opened', $this->runCommand('contabil:open-year --entity ' . $other . ' --start 2026-01-01'));
        $this->assertStringContainsString('Fiscal year 2027 (2027-01-01 to 2027-12-31) opened', $this->runCommand('contabil:open-year --entity ' . $other));
        $this->assertStringContainsString('Fiscal year FY28 (2028-01-01', $this->runCommand('contabil:open-year --entity ' . $other . ' --label FY28'));
        $this->assertCount(3, $this->uc()->listFiscalYears()->execute($other));
    }

    public function testCloseYearClosesThePeriodsAndOpensTheNextYear(): void
    {
        $this->posted('2026-03-02', 'Sale', [['1.1.02', 'D', 700], ['4.1.01', 'C', 700]]);

        $this->assertStringContainsString('Close the period starting 2026-01-01', $this->runCommand('contabil:close-year --entity ' . $this->entity . ' --result-account 3.2.01'));

        $output = $this->runCommand('contabil:close-year --entity ' . $this->entity . ' --year 2026 --result-account 3.2.01 --close-periods --open-next');

        $this->assertStringContainsString('Fiscal year 2026 closed with closing entry 2.', $output);
        $this->assertStringContainsString('Fiscal year 2027 opened', $output);

        $years = $this->uc()->listFiscalYears()->execute($this->entity);
        $this->assertSame(['CLOSED', 'OPEN'], [$years[0]->status->value, $years[1]->status->value]);
        $this->assertStringContainsString('Fiscal year "9999" does not exist', $this->runCommand('contabil:close-year --entity ' . $this->entity . ' --year 9999'));
    }

    public function testOpeningBalancesFromCsvAndJson(): void
    {
        $dir = str_replace('\\', '/', sys_get_temp_dir()) . '/contabil-opening-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/balances.csv', "\xEF\xBB\xBFaccount_code,side,amount\n1.1.01,D,1500.00\n2.1.01,C,250.5\n\n");
        file_put_contents($dir . '/balances.json', json_encode([
            ['account_code' => '1.1.01', 'side' => 'D', 'amount' => 900],
            ['account_code' => '3.1.01', 'side' => 'C', 'amount' => 900],
        ]));

        try {
            $this->assertStringContainsString('debits exceed credits by 1249.50', $this->runCommand('contabil:opening-balances --entity ' . $this->entity . ' --file ' . $dir . '/balances.csv'));

            $output = $this->runCommand('contabil:opening-balances --entity ' . $this->entity . ' --file ' . $dir . '/balances.csv --balancing-account 3.1.01');
            $this->assertStringContainsString('Opening balances of fiscal year 2026 posted as entry 1 (3 lines, 1500.00 on each side).', $output);

            $this->assertStringContainsString('already posted as entry 1', $this->runCommand('contabil:opening-balances --entity ' . $this->entity . ' --file ' . $dir . '/balances.json'));
            $this->assertStringContainsString('posted as entry 3 (2 lines, 9.00', $this->runCommand('contabil:opening-balances --entity ' . $this->entity . ' --year 2026 --file ' . $dir . '/balances.json --replace'));
            $this->assertStringContainsString('readable file', $this->runCommand('contabil:opening-balances --entity ' . $this->entity . ' --file ' . $dir . '/missing.csv'));
        } finally {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    private function runCommand(string $command): string
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();

        try {
            command($command);

            return CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
            CITestStreamFilter::removeErrorFilter();
            CITestStreamFilter::$buffer = '';
        }
    }
}
