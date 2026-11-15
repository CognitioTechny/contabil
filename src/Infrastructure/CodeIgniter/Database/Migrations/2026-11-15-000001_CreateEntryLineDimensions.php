<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\Migrations;

use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database\ContabilMigration;

/**
 * Analytic dimensions (cost centers) of entry lines: one row per line and
 * dimension type. `dimension_id` is an opaque host identifier (a lawsuit, a
 * case, a client), so it has no foreign key. `entry_id` repeats the line's
 * entry to delete and load the dimensions of an entry in one statement.
 *
 * Schema 1.1.0: only adds a table, so hosts on 1.0.0 upgrade by migrating.
 */
class CreateEntryLineDimensions extends ContabilMigration
{
    private const VERSION = '1.1.0';

    public function up(): void
    {
        $t = $this->tables;

        $this->forge->addField($this->idField() + [
            'entry_id'       => $this->refField(),
            'line_id'        => $this->refField(),
            'dimension_type' => $this->textField(50),
            'dimension_id'   => $this->textField(100),
        ]);
        $this->forge->addPrimaryKey('id');
        $this->forge->addKey(['line_id', 'dimension_type'], false, true, $t->key('uq', 'entry_line_dimensions', 'line', 'type'));
        $this->forge->addKey(['dimension_type', 'dimension_id', 'line_id'], false, false, $t->key('idx', 'entry_line_dimensions', 'type', 'id'));
        $this->forge->addKey('entry_id', false, false, $t->key('idx', 'entry_line_dimensions', 'entry'));
        $this->foreignKey('entry_id', 'entries', $t->key('fk', 'entry_line_dimensions', 'entry'));
        $this->foreignKey('line_id', 'entry_lines', $t->key('fk', 'entry_line_dimensions', 'line'));
        $this->forge->createTable($t->name('entry_line_dimensions'), true);

        $versions = $this->db->table($t->name('schema_version'));

        if ($versions->where('version', self::VERSION)->countAllResults() === 0) {
            $this->db->table($t->name('schema_version'))->insert([
                'version'    => self::VERSION,
                'applied_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
    }

    public function down(): void
    {
        $this->forge->dropTable($this->tables->name('entry_line_dimensions'), true);

        // A fresh install records the current version in the support tables
        // migration; only drop the row this migration added on an upgrade.
        $versions = $this->db->table($this->tables->name('schema_version'));

        if ($versions->where('version !=', self::VERSION)->countAllResults() > 0) {
            $this->db->table($this->tables->name('schema_version'))->where('version', self::VERSION)->delete();
        }
    }
}
