<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter;

use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Calendar\CreateFiscalYear;
use CognitioTechny\Contabil\Application\Calendar\ListFiscalYears;
use CognitioTechny\Contabil\Application\Calendar\ListPeriods;
use CognitioTechny\Contabil\Application\Chart\CreateAccount;
use CognitioTechny\Contabil\Application\Chart\CreateEntity;
use CognitioTechny\Contabil\Application\Chart\DeactivateAccount;
use CognitioTechny\Contabil\Application\Chart\DeleteAccount;
use CognitioTechny\Contabil\Application\Chart\ExportChart;
use CognitioTechny\Contabil\Application\Chart\GetAccount;
use CognitioTechny\Contabil\Application\Chart\GetAccountTree;
use CognitioTechny\Contabil\Application\Chart\ImportChart;
use CognitioTechny\Contabil\Application\Chart\ListAccounts;
use CognitioTechny\Contabil\Application\Chart\MoveAccount;
use CognitioTechny\Contabil\Application\Chart\ReactivateAccount;
use CognitioTechny\Contabil\Application\Chart\UpdateAccount;
use CognitioTechny\Contabil\Application\Configuration\Settings;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbAccountMovement;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbAccountRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbEntityRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbFiscalYearRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbTransactionRunner;

/**
 * The package use cases wired to the CodeIgniter database. Controllers and
 * commands get them from `service('contabil')`; a host can use them directly.
 */
final class UseCases
{
    private readonly DbAccountRepository $accounts;
    private readonly DbEntityRepository $entities;
    private readonly DbFiscalYearRepository $years;
    private readonly DbTransactionRunner $transactions;
    private readonly EntityGuard $guard;
    private readonly ChartPolicy $policy;

    public function __construct(private readonly Settings $settings, BaseConnection $db, ClockInterface $clock)
    {
        $this->accounts     = new DbAccountRepository($db, $settings->tables, $clock);
        $this->entities     = new DbEntityRepository($db, $settings->tables, $clock);
        $this->years        = new DbFiscalYearRepository($db, $settings->tables, $clock);
        $this->transactions = new DbTransactionRunner($db);
        $this->guard        = new EntityGuard($this->entities);
        $this->policy       = new ChartPolicy($this->accounts, new DbAccountMovement($db, $settings->tables), $settings->codeMask());
    }

    public function createEntity(): CreateEntity
    {
        return new CreateEntity($this->entities, $this->settings);
    }

    public function createAccount(): CreateAccount
    {
        return new CreateAccount($this->guard, $this->policy, $this->accounts, $this->transactions);
    }

    public function updateAccount(): UpdateAccount
    {
        return new UpdateAccount($this->policy, $this->accounts);
    }

    public function moveAccount(): MoveAccount
    {
        return new MoveAccount($this->policy, $this->accounts, $this->transactions);
    }

    public function deactivateAccount(): DeactivateAccount
    {
        return new DeactivateAccount($this->policy, $this->accounts);
    }

    public function reactivateAccount(): ReactivateAccount
    {
        return new ReactivateAccount($this->policy, $this->accounts);
    }

    public function deleteAccount(): DeleteAccount
    {
        return new DeleteAccount($this->policy, $this->accounts);
    }

    public function getAccount(): GetAccount
    {
        return new GetAccount($this->accounts);
    }

    public function listAccounts(): ListAccounts
    {
        return new ListAccounts($this->guard, $this->accounts);
    }

    public function getAccountTree(): GetAccountTree
    {
        return new GetAccountTree($this->guard, $this->accounts);
    }

    public function importChart(): ImportChart
    {
        return new ImportChart($this->guard, $this->createAccount(), $this->accounts, $this->transactions);
    }

    public function exportChart(): ExportChart
    {
        return new ExportChart($this->guard, $this->getAccountTree());
    }

    public function createFiscalYear(): CreateFiscalYear
    {
        return new CreateFiscalYear($this->guard, $this->years, $this->transactions);
    }

    public function listFiscalYears(): ListFiscalYears
    {
        return new ListFiscalYears($this->guard, $this->years);
    }

    public function listPeriods(): ListPeriods
    {
        return new ListPeriods($this->years);
    }
}
