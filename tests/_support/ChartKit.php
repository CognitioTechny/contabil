<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Chart\CreateAccount;
use CognitioTechny\Contabil\Application\Chart\DeactivateAccount;
use CognitioTechny\Contabil\Application\Chart\DeleteAccount;
use CognitioTechny\Contabil\Application\Chart\ExportChart;
use CognitioTechny\Contabil\Application\Chart\GetAccountTree;
use CognitioTechny\Contabil\Application\Chart\ImportChart;
use CognitioTechny\Contabil\Application\Chart\MoveAccount;
use CognitioTechny\Contabil\Application\Chart\ReactivateAccount;
use CognitioTechny\Contabil\Application\Chart\UpdateAccount;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Chart\CodeMask;

/**
 * Wires the chart use cases over {@see InMemoryChart}, so tests need no database.
 */
final class ChartKit
{
    public readonly InMemoryChart $store;
    public readonly CreateAccount $create;
    public readonly UpdateAccount $update;
    public readonly MoveAccount $move;
    public readonly DeactivateAccount $deactivate;
    public readonly ReactivateAccount $reactivate;
    public readonly DeleteAccount $delete;
    public readonly GetAccountTree $tree;
    public readonly ImportChart $import;
    public readonly ExportChart $export;

    public function __construct(?string $mask = null)
    {
        $this->store = new InMemoryChart();
        $policy      = new ChartPolicy($this->store, $this->store, CodeMask::fromString($mask));
        $guard       = new EntityGuard($this->store);

        $this->create     = new CreateAccount($guard, $policy, $this->store, $this->store);
        $this->update     = new UpdateAccount($policy, $this->store);
        $this->move       = new MoveAccount($policy, $this->store, $this->store);
        $this->deactivate = new DeactivateAccount($policy, $this->store);
        $this->reactivate = new ReactivateAccount($policy, $this->store);
        $this->delete     = new DeleteAccount($policy, $this->store);
        $this->tree       = new GetAccountTree($guard, $this->store);
        $this->import     = new ImportChart($guard, $this->create, $this->store, $this->store);
        $this->export     = new ExportChart($guard, $this->tree);
    }
}
