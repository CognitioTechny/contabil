<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Configuration;

use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;

/**
 * Validated, framework-free view of the package configuration.
 */
final class Settings
{
    public const BASIS_ACCRUAL = 'ACCRUAL';
    public const BASIS_CASH    = 'CASH';

    public readonly TableNames $tables;

    public function __construct(
        public readonly string $tablePrefix = 'acc_',
        public readonly string $currency = 'USD',
        public readonly int|string|null $defaultEntityId = null,
        public readonly string $apiRoute = 'contabil',
        public readonly bool $routesEnabled = true,
        public readonly bool $commandsEnabled = true,
        public readonly string $defaultBasis = self::BASIS_ACCRUAL,
        public readonly ?string $dbGroup = null,
    ) {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]{0,15}$/', $tablePrefix) !== 1) {
            throw new ConfigurationException('The table prefix must start with a letter and have at most 16 letters, digits or underscores.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ConfigurationException('The currency must be a three-letter uppercase code.');
        }

        if (! in_array($defaultBasis, [self::BASIS_ACCRUAL, self::BASIS_CASH], true)) {
            throw new ConfigurationException('The default basis must be ACCRUAL or CASH.');
        }

        if (trim($apiRoute, '/') === '') {
            throw new ConfigurationException('The API base route must not be empty.');
        }

        $this->tables = new TableNames($tablePrefix);
    }

    /**
     * @param array<string, mixed> $values Keys match the constructor arguments.
     */
    public static function fromArray(array $values): self
    {
        return new self(...array_intersect_key($values, array_flip([
            'tablePrefix', 'currency', 'defaultEntityId', 'apiRoute',
            'routesEnabled', 'commandsEnabled', 'defaultBasis', 'dbGroup',
        ])));
    }
}
