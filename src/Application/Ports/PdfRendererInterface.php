<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Ports;

use CognitioTechny\Contabil\Domain\Exception\UnsupportedOperationException;

/**
 * Optional. Turns an HTML document into PDF bytes. The package ships no PDF
 * library; without an implementation PDF export is unavailable.
 */
interface PdfRendererInterface
{
    /**
     * @return string Raw PDF bytes.
     *
     * @throws UnsupportedOperationException
     *                                       When no renderer is configured.
     */
    public function render(string $html): string;
}
