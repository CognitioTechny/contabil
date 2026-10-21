<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Application\Configuration\Settings;
use CognitioTechny\Contabil\Application\Repository\EntityRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Registers an accounted entity, the owner of a chart, calendar and ledger.
 */
final class CreateEntity
{
    public function __construct(
        private readonly EntityRepositoryInterface $entities,
        private readonly Settings $settings,
    ) {
    }

    /**
     * @return int Id of the new entity.
     */
    public function execute(string $name, ?string $taxId = null, ?string $currency = null, ?string $basis = null): int
    {
        $name     = trim($name);
        $taxId    = $taxId === null || trim($taxId) === '' ? null : trim($taxId);
        $currency = strtoupper($currency ?? $this->settings->currency);
        $basis    = strtoupper($basis ?? $this->settings->defaultBasis);

        if ($name === '' || mb_strlen($name) > 150) {
            throw new ValidationException('The entity name is required and has at most 150 characters.');
        }

        if ($taxId !== null && strlen($taxId) > 32) {
            throw new ValidationException('The tax id has at most 32 characters.');
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new ValidationException('The currency must be a three-letter code.');
        }

        if (! in_array($basis, [Settings::BASIS_ACCRUAL, Settings::BASIS_CASH], true)) {
            throw new ValidationException('The basis must be ACCRUAL or CASH.');
        }

        return $this->entities->create($name, $taxId, $currency, $basis);
    }
}
