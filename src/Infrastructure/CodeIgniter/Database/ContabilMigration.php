<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database;

use CodeIgniter\Database\Forge;
use CodeIgniter\Database\Migration;
use CognitioTechny\Contabil\Application\Configuration\Settings;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Config\Contabil;
use Throwable;

/**
 * Base class of the package migrations. Reads the table prefix and database
 * group from configuration, so physical names are built at migration time.
 */
abstract class ContabilMigration extends Migration
{
    protected Settings $settings;
    protected TableNames $tables;

    public function __construct(?Forge $forge = null)
    {
        $this->settings = config(Contabil::class)->settings();
        $this->tables   = $this->settings->tables;
        $this->DBGroup  = $this->settings->dbGroup;

        parent::__construct($forge);
    }

    /**
     * Standard surrogate key column definition.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function idField(): array
    {
        return ['id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true]];
    }

    /**
     * Definition of an unsigned BIGINT reference column.
     *
     * @return array<string, mixed>
     */
    protected function refField(bool $null = false): array
    {
        return ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => $null];
    }

    /**
     * @return array<string, mixed>
     */
    protected function textField(int $length, bool $null = false, ?string $default = null): array
    {
        $field = ['type' => 'VARCHAR', 'constraint' => $length, 'null' => $null];

        if ($default !== null) {
            $field['default'] = $default;
        }

        return $field;
    }

    /**
     * Queues a foreign key to the `id` of another package table. SQLite cannot
     * name foreign keys, so the name is only passed to the other engines.
     */
    protected function foreignKey(string $column, string $referencedLogical, string $name): void
    {
        $this->forge->addForeignKey(
            $column,
            $this->tables->name($referencedLogical),
            'id',
            'RESTRICT',
            'RESTRICT',
            $this->db->getPlatform() === 'SQLite3' ? '' : $name,
        );
    }

    /**
     * Adds a CHECK constraint where the engine enforces it. SQLite cannot add
     * one after creation, and older MySQL parses but ignores it; the domain
     * layer validates the same rule, so a failure here is not fatal.
     */
    protected function addCheck(string $logical, string $name, string $expression): void
    {
        if ($this->db->getPlatform() === 'SQLite3') {
            return;
        }

        try {
            $this->db->query(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)',
                $this->db->protectIdentifiers($this->db->prefixTable($this->tables->name($logical))),
                $this->db->escapeIdentifiers($this->tables->prefix . $name),
                $expression,
            ));
        } catch (Throwable) {
            // Engine without CHECK support; the domain layer enforces the rule.
        }
    }
}
