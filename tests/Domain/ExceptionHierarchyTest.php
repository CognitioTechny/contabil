<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use CognitioTechny\Contabil\Domain\Exception\AccessDeniedException;
use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * @internal
 */
final class ExceptionHierarchyTest extends CIUnitTestCase
{
    /**
     * @param class-string<ContabilException> $class
     */
    #[DataProvider('exceptionProvider')]
    public function testEveryExceptionDerivesFromTheRoot(string $class): void
    {
        $this->assertInstanceOf(ContabilException::class, new $class('message'));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function exceptionProvider(): iterable
    {
        foreach ([
            ValidationException::class, NotFoundException::class, ConflictException::class,
            AccessDeniedException::class, ConfigurationException::class, UnsupportedOperationException::class,
        ] as $class) {
            yield $class => [$class];
        }
    }
}
