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
