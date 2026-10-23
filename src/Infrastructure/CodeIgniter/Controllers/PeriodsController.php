<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;

/**
 * Closing and reopening months. Both need `contabil.period.manage`; reopening
 * takes a mandatory `reason` and leaves a `PeriodReopened` event.
 */
class PeriodsController extends ApiController
{
    private const MANAGE = 'contabil.period.manage';

    public function close(string $id): ResponseInterface
    {
        return $this->handle(self::MANAGE, fn (int $entity): array => $this->useCases()->managePeriod()->close($entity, (int) $id)->toArray());
    }

    public function reopen(string $id): ResponseInterface
    {
        return $this->handle(self::MANAGE, fn (int $entity): array => $this->useCases()->managePeriod()->reopen(
            $entity,
            (int) $id,
            (string) ($this->jsonBody()['reason'] ?? ''),
        )->toArray());
    }
}
