<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Chart\CodeMask;
use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * @internal
 */
final class CodeMaskTest extends CIUnitTestCase
{
    public function testNoMaskAcceptsAnything(): void
    {
        $mask = CodeMask::fromString(null);

        $mask->validate('whatever-1', 9);
        $this->assertFalse($mask->isEnforced());
        $this->assertNull($mask->maxLevel());
    }

    public function testEachLevelUsesItsOwnSegments(): void
    {
        $mask = CodeMask::fromString('9.9.99.999');

        $mask->validate('1', 1);
        $mask->validate('1.2', 2);
        $mask->validate('1.2.03', 3);
        $mask->validate('1.2.03.004', 4);
        $this->assertSame(4, $mask->maxLevel());
    }

    #[DataProvider('provideRejectsCodesThatDoNotFit')]
    public function testRejectsCodesThatDoNotFit(string $code, int $level): void
    {
        $this->expectException(ValidationException::class);

        CodeMask::fromString('9.9.99.999')->validate($code, $level);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideRejectsCodesThatDoNotFit(): iterable
    {
        yield 'too many segments for the level' => ['1.2', 1];

        yield 'too few segments for the level' => ['1', 2];

        yield 'wrong width' => ['1.2.3', 3];

        yield 'letters where digits go' => ['A', 1];

        yield 'trailing text' => ['1.2.03.004x', 4];

        yield 'beyond the deepest level' => ['1.2.03.004.5', 5];
    }

    public function testLettersAndAlphanumericPatterns(): void
    {
        $mask = CodeMask::fromString('A-XX');

        $mask->validate('B', 1);
        $mask->validate('B-7z', 2);

        $this->expectException(ValidationException::class);
        $mask->validate('7-7z', 2);
    }

    public function testSingleSegmentMask(): void
    {
        $mask = CodeMask::fromString('9999');

        $mask->validate('1010', 1);
        $this->expectException(ValidationException::class);
        $mask->validate('1010', 2);
    }

    #[DataProvider('provideMalformedMasksAreConfigurationErrors')]
    public function testMalformedMasksAreConfigurationErrors(string $mask): void
    {
        $this->expectException(ConfigurationException::class);

        CodeMask::fromString($mask);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideMalformedMasksAreConfigurationErrors(): iterable
    {
        yield 'foreign characters' => ['9.Z'];

        yield 'two separators' => ['9.9-9'];

        yield 'trailing separator' => ['9.9.'];

        yield 'leading separator' => ['.9'];
    }
}
