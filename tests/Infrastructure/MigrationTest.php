<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Config\Factories;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config\Contabil;
use Config\Services;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class MigrationTest extends DatabaseTestCase
{
    protected $migrate = false;
    protected $refresh = false;

    public function testMigrationsGoUpAndDown(): void
    {
        $tables = (new TableNames('acc_'))->all();
        $runner = Services::migrations(null, $this->db, false);
        $runner->setNamespace($this->namespace);

        $runner->latest();

        foreach ($tables as $table) {
            $this->assertTrue($this->db->tableExists($table), $table . ' missing after up');
        }

        $runner->regress(0);

        foreach ($tables as $table) {
            $this->assertFalse($this->db->tableExists($table), $table . ' still present after down');
        }
    }

    public function testRunningTwiceIsHarmless(): void
    {
        $runner = Services::migrations(null, $this->db, false);
        $runner->setNamespace($this->namespace);

        $runner->latest();
        $runner->latest();
        $this->assertSame(1, $this->db->table('acc_schema_version')->countAllResults());

        $runner->regress(0);
    }

    public function testTablePrefixComesFromConfiguration(): void
    {
        $config              = new Contabil();
        $config->tablePrefix = 'ledger_';
        Factories::injectMock('config', Contabil::class, $config);

        $runner = Services::migrations(null, $this->db, false);
        $runner->setNamespace($this->namespace);
        $runner->latest();

        try {
            $tables = (new TableNames('ledger_'))->all();

            foreach ($tables as $table) {
                $this->assertTrue($this->db->tableExists($table), $table . ' missing');
            }
            $this->assertFalse($this->db->tableExists('acc_entries'));
            $this->assertContains('ledger_idx_entries_source', array_keys($this->db->getIndexData('ledger_entries')));
        } finally {
            $runner->regress(0);
            Factories::reset('config');
        }
    }
}
