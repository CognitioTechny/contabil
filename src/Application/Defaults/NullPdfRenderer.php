<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Defaults;

use CognitioTechny\Contabil\Application\Ports\PdfRendererInterface;
use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;

final class NullPdfRenderer implements PdfRendererInterface
{
    public function render(string $html): string
    {
        throw new UnsupportedOperationException('No PDF renderer is configured.');
    }
}
