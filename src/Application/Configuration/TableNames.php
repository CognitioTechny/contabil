<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Configuration;

use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;

/**
 * Resolves logical table names to prefixed physical names. Table, foreign key
 * and index names are all built from the prefix at migration time.
 */
final class TableNames
{
    public const LOGICAL = [
        'entities', 'fiscal_years', 'periods', 'accounts', 'entries', 'entry_lines',
        'settlements', 'balances', 'posting_rules', 'sequences', 'schema_version',
    ];

    public function __construct(public readonly string $prefix)
    {
    }

    /**
     * @param string $logical One of {@see self::LOGICAL}, without prefix.
     */
    public function name(string $logical): string
    {
        if (! in_array($logical, self::LOGICAL, true)) {
            throw new ConfigurationException(sprintf('Unknown table "%s".', $logical));
        }

        return $this->prefix . $logical;
    }

    /**
     * @return list<string> Every physical table name, in creation order.
     */
    public function all(): array
    {
        return array_map(fn (string $logical): string => $this->prefix . $logical, self::LOGICAL);
    }

    /**
     * Builds an index or foreign key name that carries the prefix.
     */
    public function key(string $kind, string $logical, string ...$columns): string
    {
        return $this->prefix . $kind . '_' . $logical . '_' . implode('_', $columns);
    }
}
