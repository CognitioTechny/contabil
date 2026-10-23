<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Infrastructure;

use CodeIgniter\Test\Filters\CITestStreamFilter;
use CognitioTechny\Contabil\Application\Chart\AccountNode;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountType;
use CognitioTechny\Contabil\Infrastructure\CodeIgniter\Commands\SeedChartCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DatabaseTestCase;

/**
 * @internal
 */
final class SeedChartTest extends DatabaseTestCase
{
    public function testBothModelsShip(): void
    {
        $this->assertSame(['generic', 'professional-services'], SeedChartCommand::models());
    }

    #[DataProvider('models')]
    public function testSeedingAnEmptyEntityYieldsAValidTree(string $model): void
    {
        $entity = service('contabil')->createEntity()->execute('Seeded');

        $output = $this->spark('contabil:seed-chart --model ' . $model . ' --entity ' . $entity);
        $this->assertStringContainsString('created', $output);
        $this->assertStringContainsString('accountant', $output);

        $accounts = service('contabil')->listAccounts()->execute($entity, true);
        $this->assertGreaterThan(30, count($accounts));

        $types = [];

        foreach (service('contabil')->getAccountTree()->execute($entity) as $root) {
            $types[] = $root->account->type;
            $this->assertSame(1, $root->account->level);
            $this->assertFalse($root->account->acceptsEntries);
            $this->assertNoLeafGroups($root);
        }

        $this->assertSame([AccountType::Asset, AccountType::Liability, AccountType::Equity, AccountType::Revenue, AccountType::Expense], $types);

        $cash = array_filter($accounts, static fn ($a) => $a->isCashAccount);
        $this->assertNotEmpty($cash);

        foreach ($cash as $account) {
            $this->assertSame(AccountType::Asset, $account->type);
            $this->assertTrue($account->acceptsEntries);
        }

        $this->assertTrue($this->byCode($accounts, '1.1.01') !== null);
        $this->assertTrue($this->byCode($accounts, '3.2.03')->isContra(), 'Distributions are a contra equity account.');
    }

    #[DataProvider('models')]
    public function testSeedingTwiceChangesNothing(string $model): void
    {
        $entity = service('contabil')->createEntity()->execute('Seeded');

        $this->spark('contabil:seed-chart --model ' . $model . ' --entity ' . $entity);
        $before = $this->db->table('acc_accounts')->orderBy('id')->get()->getResultArray();

        $output = $this->spark('contabil:seed-chart --model ' . $model . ' --entity=' . $entity);
        $after  = $this->db->table('acc_accounts')->orderBy('id')->get()->getResultArray();

        $this->assertSame($before, $after);
        $this->assertStringContainsString('0 account(s) created', $output);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function models(): iterable
    {
        foreach (SeedChartCommand::models() as $model) {
            yield $model => [$model];
        }
    }

    public function testProfessionalServicesKeepsClientFundsOutOfEquity(): void
    {
        $entity = service('contabil')->createEntity()->execute('Firm');
        $this->spark('contabil:seed-chart --model professional-services --entity ' . $entity);

        $accounts = service('contabil')->listAccounts()->execute($entity, true);

        $trust = $this->byCode($accounts, '1.2.01');
        $owed  = $this->byCode($accounts, '2.2.01');

        $this->assertStringContainsString('Client trust', $trust->name);
        $this->assertTrue($trust->isCashAccount);
        $this->assertSame(AccountType::Liability, $owed->type);

        foreach ($accounts as $account) {
            if ($account->type === AccountType::Equity) {
                $this->assertStringNotContainsStringIgnoringCase('client', $account->name);
            }
        }
    }

    public function testUnknownModelAndMissingEntityFail(): void
    {
        $this->assertStringContainsString('Unknown chart', $this->spark('contabil:seed-chart --model ../secrets --entity 1'));
        $this->assertStringContainsString('--entity', $this->spark('contabil:seed-chart --model generic'));
        $this->assertStringContainsString('does not exist', $this->spark('contabil:seed-chart --model generic --entity 4242'));
    }

    public function testCreateEntityCommand(): void
    {
        $this->assertStringContainsString('Entity created with id', $this->spark('contabil:create-entity --name Acme --currency usd'));
        $this->assertSame(1, $this->db->table('acc_entities')->where('name', 'Acme')->countAllResults());
        $this->assertStringContainsString('name is required', $this->spark('contabil:create-entity'));
    }

    /**
     * @param list<Account> $accounts
     */
    private function byCode(array $accounts, string $code): ?Account
    {
        foreach ($accounts as $account) {
            if ($account->code === $code) {
                return $account;
            }
        }

        return null;
    }

    private function assertNoLeafGroups(AccountNode $node): void
    {
        if ($node->children === []) {
            $this->assertTrue($node->account->acceptsEntries, 'The leaf ' . $node->account->code . ' must accept entries.');
        } else {
            $this->assertFalse($node->account->acceptsEntries, 'The group ' . $node->account->code . ' must not accept entries.');
        }

        foreach ($node->children as $child) {
            $this->assertNoLeafGroups($child);
        }
    }

    private function spark(string $commandLine): string
    {
        CITestStreamFilter::registration();
        CITestStreamFilter::addOutputFilter();
        CITestStreamFilter::addErrorFilter();

        try {
            command($commandLine);

            return CITestStreamFilter::$buffer;
        } finally {
            CITestStreamFilter::removeOutputFilter();
            CITestStreamFilter::removeErrorFilter();
            CITestStreamFilter::$buffer = '';
        }
    }
}
