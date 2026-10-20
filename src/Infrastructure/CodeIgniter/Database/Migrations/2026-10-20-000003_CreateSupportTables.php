<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\Migrations;

use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\ContabilMigration;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\SchemaVersion;

/**
 * Balance cache, posting rules and schema version marker.
 */
class CreateSupportTables extends ContabilMigration
{
    public function up(): void
    {
        $t = $this->tables;

        // Balances: a recomputable cache; entry lines stay the source of truth.
        $this->forge->addField([
            'account_id'      => $this->refField(),
            'period_id'       => $this->refField(),
            'opening_balance' => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
            'total_debits'    => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'total_credits'   => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
            'closing_balance' => ['type' => 'BIGINT', 'constraint' => 20, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey(['account_id', 'period_id']);
        $this->forge->addKey('period_id', false, false, $t->key('idx', 'balances', 'period'));
        $this->foreignKey('account_id', 'accounts', $t->key('fk', 'balances', 'account'));
        $this->foreignKey('period_id', 'periods', $t->key('fk', 'balances', 'period'));
        $this->forge->createTable($t->name('balances'), true);

        // Posting rules: optional map from a host source event to a pair of accounts.
        $this->forge->addField($this->idField() + [
            'entity_id'         => $this->refField(),
            'event'             => $this->textField(100),
            'condition'         => ['type' => 'TEXT', 'null' => true],
            'debit_account_id'  => $this->refField(),
            'credit_account_id' => $this->refField(),
            'active'            => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'created_at'        => ['type' => 'DATETIME', 'null' => true],
            'updated_at'        => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_id', 'event'], false, false, $t->key('idx', 'posting_rules', 'entity', 'event'));
        $this->foreignKey('entity_id', 'entities', $t->key('fk', 'posting_rules', 'entity'));
        $this->foreignKey('debit_account_id', 'accounts', $t->key('fk', 'posting_rules', 'debit'));
        $this->foreignKey('credit_account_id', 'accounts', $t->key('fk', 'posting_rules', 'credit'));
        $this->forge->createTable($t->name('posting_rules'), true);

        // Schema version: one row per applied package schema version.
        $this->forge->addField([
            'version'    => $this->textField(20),
            'applied_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addPrimaryKey('version');
        $this->forge->createTable($t->name('schema_version'), true);

        $this->db->table($t->name('schema_version'))->insert([
            'version'    => SchemaVersion::CURRENT,
            'applied_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function down(): void
    {
        foreach (['schema_version', 'posting_rules', 'balances'] as $logical) {
            $this->forge->dropTable($this->tables->name($logical), true);
        }
    }
}
