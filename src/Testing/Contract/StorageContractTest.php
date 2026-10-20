<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\StorageInterface;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;

/**
 * Contract every StorageInterface implementation must satisfy.
 */
abstract class StorageContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): StorageInterface;

    public function testStoredContentsComeBackUnchanged(): void
    {
        $storage = $this->createSubject();
        $storage->put('exports/ledger.csv', "a,b\n1,2\n");

        $this->assertTrue($storage->exists('exports/ledger.csv'));
        $this->assertSame("a,b\n1,2\n", $storage->get('exports/ledger.csv'));
    }

    public function testPutReplacesExistingContents(): void
    {
        $storage = $this->createSubject();
        $storage->put('a.txt', 'one');
        $storage->put('a.txt', 'two');

        $this->assertSame('two', $storage->get('a.txt'));
    }

    public function testGetOfAMissingFileThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->createSubject()->get('missing.txt');
    }

    public function testDeleteRemovesTheFileAndToleratesMissingOnes(): void
    {
        $storage = $this->createSubject();
        $storage->put('a.txt', 'x');
        $storage->delete('a.txt');
        $storage->delete('a.txt');

        $this->assertFalse($storage->exists('a.txt'));
    }
}
