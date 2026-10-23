<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Database\Exceptions\DatabaseException;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\SchemaVersion;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class SchemaTest extends DatabaseTestCase
{
    private TableNames $tables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tables = new TableNames('acc_');
    }

    public function testEveryTableExists(): void
    {
        foreach ($this->tables->all() as $table) {
            $this->assertTrue($this->db->tableExists($table), $table . ' is missing');
        }
    }

    public function testSchemaVersionIsRecorded(): void
    {
        $this->seeInDatabase('acc_schema_version', ['version' => SchemaVersion::CURRENT]);
    }

    public function testAllForeignKeysPointToPackageTables(): void
    {
        $count = 0;

        foreach ($this->tables->all() as $table) {
            foreach ($this->db->getForeignKeyData($table) as $foreignKey) {
                $count++;
                $this->assertContains(substr($foreignKey->foreign_table_name, strlen($this->db->DBPrefix)), $this->tables->all(), $table . ' references a non-package table');
            }
        }

        $this->assertGreaterThan(10, $count);
    }

    public function testRequiredIndexesExist(): void
    {
        $expected = [
            'acc_entries'     => ['acc_idx_entries_entity_accrual', 'acc_idx_entries_entity_cash', 'acc_idx_entries_source', 'acc_uq_entries_entity_idempotency', 'acc_uq_entries_entity_chain', 'acc_uq_entries_entity_year_number'],
            'acc_entry_lines' => ['acc_idx_entry_lines_account_entry'],
            'acc_accounts'    => ['acc_uq_accounts_entity_code'],
        ];

        foreach ($expected as $table => $names) {
            $actual = array_keys($this->db->getIndexData($table));

            foreach ($names as $name) {
                $this->assertContains($name, $actual, "{$table} lacks {$name}");
            }
        }
    }

    public function testAccountCodeIsUniquePerEntity(): void
    {
        $entityA = $this->entity('A');
        $entityB = $this->entity('B');

        $this->account($entityA, '1.1');
        $this->account($entityB, '1.1');

        $this->expectException(DatabaseException::class);
        $this->account($entityA, '1.1');
    }

    public function testIdempotencyKeyIsUniquePerEntity(): void
    {
        $entityA = $this->entity('A');
        $entityB = $this->entity('B');

        $this->entryRow($entityA, 'sys:invoice:1');
        $this->entryRow($entityB, 'sys:invoice:1');

        $this->expectException(DatabaseException::class);
        $this->entryRow($entityA, 'sys:invoice:1');
    }

    public function testEntriesAcceptOpaqueHostReferences(): void
    {
        $entity = $this->entity('A');

        $this->db->table('acc_entries')->insert([
            'entity_id'   => $entity, 'accrual_date' => '2026-01-31', 'recorded_at' => '2026-01-31 10:00:00',
            'description' => 'Invoice', 'status' => 'DRAFT', 'user_id' => 'uuid-7f3a',
            'source_type' => 'invoice', 'source_id' => 'INV-0001',
        ]);

        $this->seeInDatabase('acc_entries', ['source_id' => 'INV-0001', 'user_id' => 'uuid-7f3a']);
    }

    public function testLinesRejectUnknownAccountsAndEntries(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->table('acc_entry_lines')->insert(['entry_id' => 999, 'account_id' => 999, 'side' => 'D', 'amount' => 100]);
    }

    private function entity(string $name): int
    {
        $this->db->table('acc_entities')->insert(['name' => $name, 'currency' => 'USD', 'default_basis' => 'ACCRUAL']);

        return (int) $this->db->insertID();
    }

    private function account(int $entity, string $code): void
    {
        $this->db->table('acc_accounts')->insert([
            'entity_id' => $entity, 'code' => $code, 'name' => 'Cash', 'type' => 'ASSET', 'nature' => 'DEBIT',
        ]);
    }

    private function entryRow(int $entity, string $key): void
    {
        $this->db->table('acc_entries')->insert([
            'entity_id'   => $entity, 'accrual_date' => '2026-01-31', 'recorded_at' => '2026-01-31 10:00:00',
            'description' => 'Test', 'status' => 'DRAFT', 'idempotency_key' => $key,
        ]);
    }
}
