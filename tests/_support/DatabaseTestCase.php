<?php

declare(strict_types=1);

namespace Tests\Support;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Base class for tests that need the package schema. It migrates the package
 * namespace into the `tests` database group (SQLite in memory by default).
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = 'CognitioTechny\Contabil\Infrastructure\CodeIgniter';
    protected $migrate   = true;
    protected $refresh   = true;
}
