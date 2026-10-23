<?php

declare(strict_types=1);

namespace Tests\Support;

use CognitioTechny\Contabil\Application\Defaults\FixedClock;
use CognitioTechny\Contabil\Application\Defaults\StaticCurrentUser;
use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Entry\EntryData;
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
use CognitioTechny\Contabil\Application\Period\ManageFiscalYear;
use CognitioTechny\Contabil\Application\Period\ManagePeriod;
use CognitioTechny\Contabil\Application\PostingRule\PostingRules;
use CognitioTechny\Contabil\Application\Reports\AccountMovements;
use CognitioTechny\Contabil\Application\Reports\EntryListingBuilder;
use CognitioTechny\Contabil\Application\Reports\GeneralLedgerBuilder;
use CognitioTechny\Contabil\Application\Reports\StatementBuilder;
use CognitioTechny\Contabil\Application\Reports\TrialBalanceBuilder;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Application\Repository\TransactionRunnerInterface;
use CognitioTechny\Contabil\Application\Support\EventPublisher;
use CognitioTechny\Contabil\Domain\Calendar\FiscalYear;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\PostingPolicy;
use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The accounting use cases over in-memory storage, so Domain and Application
 * are exercised without a database or CodeIgniter. Entity 1 exists from the
 * start.
 */
final class LedgerKit
{
    public const ENTITY = 1;

    public readonly ChartKit $chart;
    public readonly InMemoryFiscalYears $years;
    public readonly InMemoryLedger $ledger;
    public readonly RecordingDispatcher $dispatcher;
    public readonly TransactionRunnerInterface $transactions;
    public readonly PostingPolicy $policy;
    public readonly EntryPoster $poster;
    public readonly EntryRecorder $recorder;
    public readonly EntryReverser $reverser;
    public readonly CreateEntry $create;
    public readonly PostBatch $batch;
    public readonly ManageDraft $drafts;
    public readonly PostEntry $post;
    public readonly ReverseEntry $reverse;
    public readonly SettleEntry $settle;
    public readonly EntryQueries $queries;
    public readonly ManagePeriod $periods;
    public readonly ManageFiscalYear $fiscalYears;
    public readonly Integrity $integrity;
    public readonly TrialBalanceBuilder $trialBalance;
    public readonly GeneralLedgerBuilder $generalLedger;
    public readonly EntryListingBuilder $listing;
    public readonly StatementBuilder $statements;
    public readonly PostingRules $rules;

    /**
     * @var array<string, int> Account code => id, filled by {@see accounts()}.
     */
    public array $ids = [];

    public function __construct(?string $resultAccountCode = null, ?EventDispatcherInterface $dispatcher = null)
    {
        $this->chart      = new ChartKit();
        $this->years      = new InMemoryFiscalYears();
        $this->ledger     = new InMemoryLedger($this->chart->store);
        $this->dispatcher = new RecordingDispatcher();

        $chart  = $this->chart->store;
        $years  = $this->years;
        $ledger = $this->ledger;

        $this->transactions = new class ($chart, $years, $ledger) implements TransactionRunnerInterface {
            public function __construct(
                private readonly TransactionRunnerInterface $a,
                private readonly TransactionRunnerInterface $b,
                private readonly TransactionRunnerInterface $c,
            ) {
            }

            public function run(callable $work): mixed
            {
                return $this->a->run(fn () => $this->b->run(fn () => $this->c->run($work)));
            }
        };

        $guard  = new EntityGuard($chart);
        $events = new EventPublisher($dispatcher ?? $this->dispatcher);
        $user   = new StaticCurrentUser('u1');
        $clock  = new FixedClock(new DateTimeImmutable('2026-03-01 12:00:00'));

        $this->policy   = new PostingPolicy($chart, $years);
        $this->poster   = new EntryPoster($ledger, $this->policy, $ledger, $clock);
        $this->recorder = new EntryRecorder($ledger, $this->policy, $this->poster);
        $this->reverser = new EntryReverser($ledger, $this->poster);

        $this->create        = new CreateEntry($guard, $this->recorder, $this->transactions, $user, $events);
        $this->batch         = new PostBatch($guard, $this->recorder, $this->transactions, $user, $events);
        $this->drafts        = new ManageDraft($ledger, $this->policy, $this->transactions);
        $this->post          = new PostEntry($ledger, $this->poster, $this->transactions, $events);
        $this->reverse       = new ReverseEntry($this->reverser, $this->transactions, $user, $events);
        $this->settle        = new SettleEntry($ledger, $this->transactions, $events);
        $this->queries       = new EntryQueries($guard, $ledger);
        $this->periods       = new ManagePeriod($years, $ledger, $this->transactions, $user, $events);
        $this->fiscalYears   = new ManageFiscalYear($years, $chart, $ledger, $ledger, $this->poster, $this->reverser, $this->transactions, $user, $events, $resultAccountCode);
        $this->integrity     = new Integrity($guard, $ledger);
        $this->trialBalance  = new TrialBalanceBuilder($guard, $chart, $ledger, $ledger);
        $this->generalLedger = new GeneralLedgerBuilder($guard, $chart, $ledger);
        $this->listing       = new EntryListingBuilder($guard, $chart, $ledger);
        $this->statements    = new StatementBuilder($guard, $chart, $chart, $ledger, new AccountMovements($ledger));
        $this->rules         = new PostingRules($guard, new InMemoryPostingRules(), $chart, $this->create);
    }

    /**
     * Creates the 2026 calendar and a small chart; ids are available in {@see $ids}.
     *
     *   1.1.01 Cash (asset, cash)      1.1.02 Receivables (asset)
     *   2.1.01 Payables (liability)    3.1.01 Capital (equity)   3.2.01 Retained result (equity)
     *   4.1.01 Sales (revenue)         4.9.01 Sales returns (contra revenue)
     *   5.1.01 Rent (expense)          5.1.02 Supplies (expense)
     */
    public function standard(): self
    {
        $this->years->insert(...FiscalYear::start(self::ENTITY, new DateTimeImmutable('2026-01-01')));

        $this->accounts([
            ['1', 'Assets', AccountType::Asset, false],
            ['1.1.01', 'Cash', AccountType::Asset, false, true],
            ['1.1.02', 'Receivables', AccountType::Asset, false],
            ['2', 'Liabilities', AccountType::Liability, false],
            ['2.1.01', 'Payables', AccountType::Liability, false],
            ['3', 'Equity', AccountType::Equity, false],
            ['3.1.01', 'Capital', AccountType::Equity, false],
            ['3.2.01', 'Retained result', AccountType::Equity, false],
            ['4', 'Revenue', AccountType::Revenue, false],
            ['4.1.01', 'Sales', AccountType::Revenue, false],
            ['4.9.01', 'Sales returns', AccountType::Revenue, true],
            ['5', 'Expenses', AccountType::Expense, false],
            ['5.1.01', 'Rent', AccountType::Expense, false],
            ['5.1.02', 'Supplies', AccountType::Expense, false],
        ]);

        return $this;
    }

    /**
     * @param list<array{0: string, 1: string, 2: AccountType, 3: bool, 4?: bool}> $definitions Code, name, type, contra, cash flag.
     */
    public function accounts(array $definitions): void
    {
        foreach ($definitions as $definition) {
            [$code, $name, $type, $contra] = $definition;

            $parent = null;

            foreach (array_keys($this->ids) as $existing) {
                if (str_starts_with($code, $existing . '.') && ($parent === null || strlen($existing) > strlen($parent))) {
                    $parent = $existing;
                }
            }

            $account = $this->chart->create->execute(
                self::ENTITY,
                $code,
                $name,
                $type->value,
                $parent === null ? null : $this->ids[$parent],
                $contra,
                null,
                $definition[4] ?? false,
            );

            $this->ids[$code] = (int) $account->id;
        }
    }

    /**
     * @param list<array{string, string, int}> $lines Account code, D or C, amount.
     */
    public function entry(string $date, string $description, array $lines, ?string $cashDate = null, ?string $key = null, ?string $sourceType = null, ?string $sourceId = null): EntryData
    {
        return new EntryData(
            $date,
            $description,
            array_map(fn (array $l): array => ['account_id' => $this->ids[$l[0]], 'side' => $l[1], 'amount' => $l[2]], $lines),
            $cashDate,
            $sourceType,
            $sourceId,
            $key,
        );
    }

    /**
     * @param list<array{string, string, int}> $lines
     */
    public function posted(string $date, string $description, array $lines, ?string $cashDate = null, ?string $key = null, ?string $sourceType = null, ?string $sourceId = null): JournalEntry
    {
        return $this->create->execute(self::ENTITY, $this->entry($date, $description, $lines, $cashDate, $key, $sourceType, $sourceId), true)->entry;
    }
}
