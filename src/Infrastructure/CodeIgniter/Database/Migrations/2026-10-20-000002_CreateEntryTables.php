<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\Migrations;

use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\ContabilMigration;

/**
 * Journal entries, their lines, settlements and the gap-free number counters.
 *
 * `user_id`, `source_type` and `source_id` are opaque host identifiers: they
 * have no foreign key. `chain_position` orders the per-entity hash chain and is
 * set when an entry is posted.
 */
class CreateEntryTables extends ContabilMigration
{
    public function up(): void
    {
        $t = $this->tables;

        // Entries
        $this->forge->addField($this->idField() + [
            'entity_id'       => $this->refField(),
            'fiscal_year_id'  => $this->refField(true),
            'number'          => $this->refField(true),
            'accrual_date'    => ['type' => 'DATE'],
            'cash_date'       => ['type' => 'DATE', 'null' => true],
            'recorded_at'     => ['type' => 'DATETIME'],
            'description'     => $this->textField(500),
            'status'          => $this->textField(10, false, 'DRAFT'),
            'reversal_of_id'  => $this->refField(true),
            'reversal_reason' => $this->textField(500, true),
            'source_type'     => $this->textField(100, true),
            'source_id'       => $this->textField(100, true),
            'idempotency_key' => $this->textField(191, true),
            'user_id'         => $this->textField(100, true),
            'hash'            => $this->textField(64, true),
            'previous_hash'   => $this->textField(64, true),
            'chain_position'  => $this->refField(true),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_id', 'accrual_date'], false, false, $t->key('idx', 'entries', 'entity', 'accrual'));
        $this->forge->addKey(['entity_id', 'cash_date'], false, false, $t->key('idx', 'entries', 'entity', 'cash'));
        $this->forge->addKey(['source_type', 'source_id'], false, false, $t->key('idx', 'entries', 'source'));
        $this->forge->addKey(['entity_id', 'idempotency_key'], false, true, $t->key('uq', 'entries', 'entity', 'idempotency'));
        $this->forge->addKey(['entity_id', 'fiscal_year_id', 'number'], false, true, $t->key('uq', 'entries', 'entity', 'year', 'number'));
        $this->forge->addKey(['entity_id', 'chain_position'], false, true, $t->key('uq', 'entries', 'entity', 'chain'));
        $this->forge->addKey('reversal_of_id', false, false, $t->key('idx', 'entries', 'reversal'));
        $this->foreignKey('entity_id', 'entities', $t->key('fk', 'entries', 'entity'));
        $this->foreignKey('fiscal_year_id', 'fiscal_years', $t->key('fk', 'entries', 'year'));
        $this->foreignKey('reversal_of_id', 'entries', $t->key('fk', 'entries', 'reversal'));
        $this->forge->createTable($t->name('entries'), true);
        $this->addCheck('entries', 'ck_entries_status', "status IN ('DRAFT', 'POSTED', 'REVERSED')");

        // Entry lines: amount is in minor units and always positive; the sign comes from `side`.
        $this->forge->addField($this->idField() + [
            'entry_id'         => $this->refField(),
            'account_id'       => $this->refField(),
            'side'             => $this->textField(1),
            'amount'           => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'line_description' => $this->textField(500, true),
            'position'         => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 1],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['account_id', 'entry_id'], false, false, $t->key('idx', 'entry_lines', 'account', 'entry'));
        $this->forge->addKey(['entry_id', 'position'], false, false, $t->key('idx', 'entry_lines', 'entry', 'position'));
        $this->foreignKey('entry_id', 'entries', $t->key('fk', 'entry_lines', 'entry'));
        $this->foreignKey('account_id', 'accounts', $t->key('fk', 'entry_lines', 'account'));
        $this->forge->createTable($t->name('entry_lines'), true);
        $this->addCheck('entry_lines', 'ck_entry_lines_amount', 'amount > 0');
        $this->addCheck('entry_lines', 'ck_entry_lines_side', "side IN ('D', 'C')");

        // Settlements
        $this->forge->addField($this->idField() + [
            'settlement_entry_id' => $this->refField(),
            'settled_entry_id'    => $this->refField(),
            'amount'              => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
            'date'                => ['type' => 'DATE'],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey('settled_entry_id', false, false, $t->key('idx', 'settlements', 'settled'));
        $this->forge->addKey('settlement_entry_id', false, false, $t->key('idx', 'settlements', 'settlement'));
        $this->foreignKey('settlement_entry_id', 'entries', $t->key('fk', 'settlements', 'settlement'));
        $this->foreignKey('settled_entry_id', 'entries', $t->key('fk', 'settlements', 'settled'));
        $this->forge->createTable($t->name('settlements'), true);
        $this->addCheck('settlements', 'ck_settlements_amount', 'amount > 0');

        // Counters for gap-free numbering, locked inside the posting transaction.
        $this->forge->addField($this->idField() + [
            'entity_id'      => $this->refField(),
            'fiscal_year_id' => $this->refField(),
            'last_number'    => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'default' => 0],
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['entity_id', 'fiscal_year_id'], false, true, $t->key('uq', 'sequences', 'entity', 'year'));
        $this->foreignKey('entity_id', 'entities', $t->key('fk', 'sequences', 'entity'));
        $this->foreignKey('fiscal_year_id', 'fiscal_years', $t->key('fk', 'sequences', 'year'));
        $this->forge->createTable($t->name('sequences'), true);
    }

    public function down(): void
    {
        foreach (['sequences', 'settlements', 'entry_lines', 'entries'] as $logical) {
            $this->forge->dropTable($this->tables->name($logical), true);
        }
    }
}
