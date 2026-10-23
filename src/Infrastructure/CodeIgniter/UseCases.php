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
use CognitioTechny\Contabil\Application\Defaults\NullEventDispatcher;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentUser;
use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Entry\EntryPoster;
use CognitioTechny\Contabil\Application\Entry\EntryQueries;
use CognitioTechny\Contabil\Application\Entry\EntryRecorder;
use CognitioTechny\Contabil\Application\Entry\EntryReverser;
use CognitioTechny\Contabil\Application\Entry\ManageDraft;
use CognitioTechny\Contabil\Application\Entry\PostBatch;
use CognitioTechny\Contabil\Application\Entry\PostEntry;
use CognitioTechny\Contabil\Application\Entry\ReverseEntry;
use CognitioTechny\Contabil\Application\Entry\SettleEntry;
use CognitioTechny\Contabil\Application\Ledger\Integrity;
use CognitioTechny\Contabil\Application\Ledger\RecalculateBalances;
use CognitioTechny\Contabil\Application\Period\ManageFiscalYear;
use CognitioTechny\Contabil\Application\Period\ManagePeriod;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Application\Ports\CurrentUserInterface;
use CognitioTechny\Contabil\Application\PostingRule\PostingRules;
use CognitioTechny\Contabil\Application\Reports\AccountMovements;
use CognitioTechny\Contabil\Application\Reports\EntryListingBuilder;
use CognitioTechny\Contabil\Application\Reports\GeneralLedgerBuilder;
use CognitioTechny\Contabil\Application\Reports\ReportRenderer;
use CognitioTechny\Contabil\Application\Reports\StatementBuilder;
use CognitioTechny\Contabil\Application\Reports\TrialBalanceBuilder;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Chart\ChartPolicy;
use CognitioTechny\Contabil\Domain\Entry\PostingPolicy;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbAccountMovement;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbAccountRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbBalanceRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbEntityRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbEntryRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbFiscalYearRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbPostingRuleRepository;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbReportReader;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories\DbTransactionRunner;
use Psr\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * The package use cases wired to the CodeIgniter database. Controllers and
 * commands get them from `service('contabil')`; a host can use them directly.
 */
final class UseCases
{
    /**
     * How long, in milliseconds, a SQLite connection waits for another writer
     * before it gives up. Other engines wait on row locks on their own.
     */
    public const SQLITE_BUSY_TIMEOUT = 30000;

    private readonly DbAccountRepository $accounts;
    private readonly DbEntityRepository $entities;
    private readonly DbFiscalYearRepository $years;
    private readonly DbEntryRepository $entries;
    private readonly DbBalanceRepository $balances;
    private readonly DbReportReader $reader;
    private readonly DbPostingRuleRepository $ruleStore;
    private readonly DbTransactionRunner $transactions;
    private readonly EntityGuard $guard;
    private readonly ChartPolicy $policy;
    private readonly PostingPolicy $postingPolicy;
    private readonly EntryPoster $poster;
    private readonly EventPublisher $events;
    private readonly CurrentUserInterface $user;

    public function __construct(
        private readonly Settings $settings,
        BaseConnection $db,
        ClockInterface $clock,
        ?CurrentUserInterface $user = null,
        ?EventDispatcherInterface $dispatcher = null,
    ) {
        if ($db->getPlatform() === 'SQLite3') {
            $db->query('PRAGMA busy_timeout = ' . self::SQLITE_BUSY_TIMEOUT);
        }

        $this->accounts      = new DbAccountRepository($db, $settings->tables, $clock);
        $this->entities      = new DbEntityRepository($db, $settings->tables, $clock);
        $this->years         = new DbFiscalYearRepository($db, $settings->tables, $clock);
        $this->entries       = new DbEntryRepository($db, $settings->tables, $clock);
        $this->balances      = new DbBalanceRepository($db, $settings->tables);
        $this->reader        = new DbReportReader($db, $settings->tables);
        $this->transactions  = new DbTransactionRunner($db, $settings->tables->name('schema_version'));
        $this->guard         = new EntityGuard($this->entities);
        $this->policy        = new ChartPolicy($this->accounts, new DbAccountMovement($db, $settings->tables), $settings->codeMask());
        $this->postingPolicy = new PostingPolicy($this->accounts, $this->years);
        $this->poster        = new EntryPoster($this->entries, $this->postingPolicy, $this->balances, $clock);
        $this->events        = new EventPublisher($dispatcher ?? new NullEventDispatcher());
        $this->user          = $user ?? new StaticCurrentUser();
        $this->ruleStore     = new DbPostingRuleRepository($db, $settings->tables, $clock);
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

    public function createEntry(): CreateEntry
    {
        return new CreateEntry($this->guard, $this->recorder(), $this->transactions, $this->user, $this->events);
    }

    public function postBatch(): PostBatch
    {
        return new PostBatch($this->guard, $this->recorder(), $this->transactions, $this->user, $this->events);
    }

    public function manageDraft(): ManageDraft
    {
        return new ManageDraft($this->entries, $this->postingPolicy, $this->transactions);
    }

    public function postEntry(): PostEntry
    {
        return new PostEntry($this->entries, $this->poster, $this->transactions, $this->events);
    }

    public function reverseEntry(): ReverseEntry
    {
        return new ReverseEntry($this->reverser(), $this->transactions, $this->user, $this->events);
    }

    public function settleEntry(): SettleEntry
    {
        return new SettleEntry($this->entries, $this->transactions, $this->events);
    }

    public function entries(): EntryQueries
    {
        return new EntryQueries($this->guard, $this->entries);
    }

    public function managePeriod(): ManagePeriod
    {
        return new ManagePeriod($this->years, $this->entries, $this->transactions, $this->user, $this->events);
    }

    public function manageFiscalYear(): ManageFiscalYear
    {
        return new ManageFiscalYear(
            $this->years,
            $this->accounts,
            $this->entries,
            $this->reader,
            $this->poster,
            $this->reverser(),
            $this->transactions,
            $this->user,
            $this->events,
            $this->settings->resultAccountCode,
        );
    }

    public function recalculateBalances(): RecalculateBalances
    {
        return new RecalculateBalances($this->guard, $this->balances, $this->entries, $this->transactions);
    }

    public function integrity(): Integrity
    {
        return new Integrity($this->guard, $this->entries);
    }

    public function postingRules(): PostingRules
    {
        return new PostingRules($this->guard, $this->ruleStore, $this->accounts, $this->createEntry());
    }

    public function trialBalance(): TrialBalanceBuilder
    {
        return new TrialBalanceBuilder($this->guard, $this->accounts, $this->reader, $this->balances);
    }

    public function generalLedger(): GeneralLedgerBuilder
    {
        return new GeneralLedgerBuilder($this->guard, $this->accounts, $this->reader);
    }

    public function entryListing(): EntryListingBuilder
    {
        return new EntryListingBuilder($this->guard, $this->accounts, $this->reader);
    }

    public function statements(): StatementBuilder
    {
        return new StatementBuilder($this->guard, $this->entities, $this->accounts, $this->reader, new AccountMovements($this->reader));
    }

    public function reportRenderer(): ReportRenderer
    {
        return new ReportRenderer();
    }

    /**
     * Listener failures swallowed after commits, for hosts that want to log them.
     *
     * @return list<Throwable>
     */
    public function eventFailures(): array
    {
        return $this->events->failures();
    }

    private function recorder(): EntryRecorder
    {
        return new EntryRecorder($this->entries, $this->postingPolicy, $this->poster);
    }

    private function reverser(): EntryReverser
    {
        return new EntryReverser($this->entries, $this->poster);
    }
}
