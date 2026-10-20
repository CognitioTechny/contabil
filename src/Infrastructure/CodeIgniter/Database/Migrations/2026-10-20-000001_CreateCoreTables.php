<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\Migrations;

use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\ContabilMigration;

/**
 * Entities, fiscal calendar and chart of accounts.
 */
class CreateCoreTables extends ContabilMigration
{
    public function up(): void
    {
        $t = $this->tables;

        // Entities
        $this->forge->addField($this->idField() + [
            'name'          => $this->textField(150),
            'tax_id'        => $this->textField(32, true),
            'currency'      => $this->textField(3, false, $this->settings->currency),
            'default_basis' => $this->textField(10, false, $this->settings->defaultBasis),
            'config'        => ['type' => 'TEXT', 'null' => true],
            'created_at'    => ['type' => 'DATETIME', 'null' => true],
            'updated_at'    => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->createTable($t->name('entities'), true);
        $this->addCheck('entities', 'ck_entities_basis', "default_basis IN ('ACCRUAL', 'CASH')");

        // Fiscal years
        $this->forge->addField($this->idField() + [
            'entity_id'  => $this->refField(),
            'label'      => $this->textField(50),
            'start_date' => ['type' => 'DATE'],
            'end_date'   => ['type' => 'DATE'],
            'status'     => $this->textField(10, false, 'OPEN'),
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_id', 'start_date'], false, false, $t->key('idx', 'fiscal_years', 'entity', 'start'));
        $this->foreignKey('entity_id', 'entities', $t->key('fk', 'fiscal_years', 'entity'));
        $this->forge->createTable($t->name('fiscal_years'), true);
        $this->addCheck('fiscal_years', 'ck_fiscal_years_status', "status IN ('OPEN', 'CLOSED')");

        // Periods
        $this->forge->addField($this->idField() + [
            'fiscal_year_id' => $this->refField(),
            'start_date'     => ['type' => 'DATE'],
            'end_date'       => ['type' => 'DATE'],
            'status'         => $this->textField(10, false, 'OPEN'),
            'created_at'     => ['type' => 'DATETIME', 'null' => true],
            'updated_at'     => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['fiscal_year_id', 'start_date'], false, true, $t->key('uq', 'periods', 'year', 'start'));
        $this->foreignKey('fiscal_year_id', 'fiscal_years', $t->key('fk', 'periods', 'year'));
        $this->forge->createTable($t->name('periods'), true);
        $this->addCheck('periods', 'ck_periods_status', "status IN ('OPEN', 'CLOSED', 'LOCKED')");

        // Accounts
        $this->forge->addField($this->idField() + [
            'entity_id'       => $this->refField(),
            'code'            => $this->textField(50),
            'name'            => $this->textField(150),
            'type'            => $this->textField(20),
            'nature'          => $this->textField(10),
            'level'           => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 1],
            'parent_id'       => $this->refField(true),
            'accepts_entries' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'is_cash_account' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0],
            'active'          => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'reference_code'  => $this->textField(50, true),
            'created_at'      => ['type' => 'DATETIME', 'null' => true],
            'updated_at'      => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_id', 'code'], false, true, $t->key('uq', 'accounts', 'entity', 'code'));
        $this->forge->addKey('parent_id', false, false, $t->key('idx', 'accounts', 'parent'));
        $this->foreignKey('entity_id', 'entities', $t->key('fk', 'accounts', 'entity'));
        $this->foreignKey('parent_id', 'accounts', $t->key('fk', 'accounts', 'parent'));
        $this->forge->createTable($t->name('accounts'), true);
    }

    public function down(): void
    {
        foreach (['accounts', 'periods', 'fiscal_years', 'entities'] as $logical) {
            $this->forge->dropTable($this->tables->name($logical), true);
        }
    }
}
