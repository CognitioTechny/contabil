<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Testing\Contract;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Application\Ports\PdfRendererInterface;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;

/**
 * Contract every PdfRendererInterface implementation must satisfy: either
 * produce PDF bytes or declare the operation unsupported.
 */
abstract class PdfRendererContractTest extends CIUnitTestCase
{
    abstract protected function createSubject(): PdfRendererInterface;

    public function testRendersPdfBytesOrDeclaresItselfUnsupported(): void
    {
        try {
            $pdf = $this->createSubject()->render('<h1>Trial balance</h1>');
        } catch (UnsupportedOperationException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->assertStringStartsWith('%PDF', $pdf);
    }
}
