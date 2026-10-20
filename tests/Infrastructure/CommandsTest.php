<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\Filters\CITestStreamFilter;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class CommandsTest extends DatabaseTestCase
{
    protected $migrate = false;
    protected $refresh = false;

    public function testMigrateThenStatusReportsEverythingApplied(): void
    {
        $this->assertStringContainsString('up to date', $this->runCommand('contabil:migrate'));

        $status = $this->runCommand('contabil:status');
        $this->assertStringContainsString('CreateEntryTables', $status);
        $this->assertStringContainsString('Nothing pending', $status);
        $this->assertTrue($this->db->tableExists('acc_entries'));

        Services::migrations(null, $this->db, false)->setNamespace($this->namespace)->regress(0);
    }

    public function testStatusReportsPendingBeforeMigrating(): void
    {
        $this->assertStringContainsString('pending', $this->runCommand('contabil:status'));
    }

    private function runCommand(string $name): string
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();

        try {
            command($name);

            return CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
            CITestStreamFilter::$buffer = '';
        }
    }
}
