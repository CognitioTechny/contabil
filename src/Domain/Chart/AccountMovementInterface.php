<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

/**
 * Tells whether an account has ever received an entry line. An account with
 * movement keeps its type and nature and can only be deactivated.
 */
interface AccountMovementInterface
{
    public function hasMovement(int $accountId): bool;
}
