<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Exception;

/**
 * The operation conflicts with the current state (for example posting into a closed period).
 */
class ConflictException extends ContabilException
{
}
