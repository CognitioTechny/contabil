<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Http;

use CognitioTechny\Contabil\Domain\Exception\AccessDeniedException;
use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use Throwable;

/**
 * The only place where domain exceptions meet HTTP.
 */
final class ExceptionStatusMap
{
    /**
     * Checked in order; the first class the exception is an instance of wins.
     *
     * @var array<class-string<Throwable>, int>
     */
    private const STATUS = [
        ValidationException::class           => 422,
        NotFoundException::class             => 404,
        ConflictException::class             => 409,
        AccessDeniedException::class         => 403,
        UnsupportedOperationException::class => 501,
        ConfigurationException::class        => 500,
    ];

    public static function statusFor(Throwable $e): int
    {
        foreach (self::STATUS as $class => $status) {
            if ($e instanceof $class) {
                return $status;
            }
        }

        return 500;
    }
}
