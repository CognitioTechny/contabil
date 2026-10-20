<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Defaults;

use CognitioTechny\Contabil\Application\Defaults\NullPdfRenderer;
use CognitioTechny\Contabil\Application\Ports\PdfRendererInterface;
use CognitioTechny\Contabil\Testing\Contract\PdfRendererContractTest as Contract;

/**
 * @internal
 */
final class NullPdfRendererTest extends Contract
{
    protected function createSubject(): PdfRendererInterface
    {
        return new NullPdfRenderer();
    }
}
