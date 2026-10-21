<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Calendar;

enum YearStatus: string
{
    case Open   = 'OPEN';
    case Closed = 'CLOSED';
}
