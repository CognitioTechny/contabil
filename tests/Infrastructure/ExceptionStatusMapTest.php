<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Exception\AccessDeniedException;
use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Http\ExceptionStatusMap;
use LogicException;

/**
 * @internal
 */
final class ExceptionStatusMapTest extends CIUnitTestCase
{
    public function testMapping(): void
    {
        $this->assertSame(422, ExceptionStatusMap::statusFor(new ValidationException()));
        $this->assertSame(404, ExceptionStatusMap::statusFor(new NotFoundException()));
        $this->assertSame(409, ExceptionStatusMap::statusFor(new ConflictException()));
        $this->assertSame(403, ExceptionStatusMap::statusFor(new AccessDeniedException()));
        $this->assertSame(501, ExceptionStatusMap::statusFor(new UnsupportedOperationException()));
        $this->assertSame(500, ExceptionStatusMap::statusFor(new ConfigurationException()));
    }

    public function testUnmappedExceptionsAreServerErrors(): void
    {
        $this->assertSame(500, ExceptionStatusMap::statusFor(new ContabilException()));
        $this->assertSame(500, ExceptionStatusMap::statusFor(new LogicException()));
    }
}
