<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Exception;

use RuntimeException;

/**
 * Root of every exception thrown on purpose by the package.
 *
 * Hosts can catch this type to handle any accounting failure. The exception
 * hierarchy carries no HTTP knowledge: the mapping lives in the API layer.
 */
class ContabilException extends RuntimeException
{
}
