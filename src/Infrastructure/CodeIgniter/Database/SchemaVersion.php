<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Database;

/**
 * Version of the database schema shipped by this release.
 *
 * Policy: within a major version the schema only grows (new tables, nullable
 * or defaulted columns, new indexes). A migration never drops or renames
 * anything; removals wait for the next major version. Every migration that
 * changes the schema bumps this constant and records it in `schema_version`.
 */
final class SchemaVersion
{
    public const CURRENT = '1.0.0';
}
