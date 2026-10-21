<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Calendar;

enum PeriodStatus: string
{
    case Open   = 'OPEN';
    case Closed = 'CLOSED';
    case Locked = 'LOCKED';
}
