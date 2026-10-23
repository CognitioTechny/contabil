<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Application\Entry;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Entry\EntryHasher;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ContabilException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use ReflectionProperty;
use Tests\Support\LedgerKit;
use Throwable;

/**
 * Settlements between entries, the hash chain check and posting rules.
 *
 * @internal
 */
final class SettlementAndIntegrityTest extends CIUnitTestCase
{
    private LedgerKit $kit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kit = (new LedgerKit())->standard();
    }

    private function creditSale(int $amount = 1000): JournalEntry
    {
        return $this->kit->posted('2026-03-02', 'Credit sale', [['1.1.02', 'D', $amount], ['4.1.01', 'C', $amount]]);
    }

    private function collection(int $amount, string $date = '2026-03-20'): JournalEntry
    {
        return $this->kit->posted($date, 'Collection', [['1.1.01', 'D', $amount], ['1.1.02', 'C', $amount]], $date);
    }

    public function testSettlementLinksTwoEntries(): void
    {
        $sale       = $this->creditSale();
        $collection = $this->collection(400);

        $settlement = $this->kit->settle->execute(LedgerKit::ENTITY, (int) $collection->id, (int) $sale->id, 400);

        $this->assertSame(400, $settlement->amount);
        $this->assertSame('2026-03-20', $settlement->date->format('Y-m-d'), 'defaults to the cash date of the settlement entry');
        $this->assertCount(1, $this->kit->queries->settlementsOf(LedgerKit::ENTITY, (int) $sale->id));
        $this->assertSame('EntrySettled', $this->kit->dispatcher->names()[array_key_last($this->kit->dispatcher->names())]);
    }

    public function testPartialSettlementsAddUpToTheTotalAndNoMore(): void
    {
        $sale = $this->creditSale(1000);

        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $this->collection(600)->id, (int) $sale->id, 600);
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $this->collection(400, '2026-03-25')->id, (int) $sale->id, 400);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('exceeds what is still open');
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $this->collection(1, '2026-03-26')->id, (int) $sale->id, 1);
    }

    public function testSettlementEntryCannotApplyMoreThanItHas(): void
    {
        $sale       = $this->creditSale(1000);
        $collection = $this->collection(300);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('only 300 left');
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $collection->id, (int) $sale->id, 500);
    }

    public function testAnEntryThatMovedCashByItselfCannotBeSettled(): void
    {
        $direct     = $this->kit->posted('2026-03-02', 'Cash sale', [['1.1.01', 'D', 100], ['4.1.01', 'C', 100]], '2026-03-02');
        $collection = $this->collection(100);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already moved cash');
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $collection->id, (int) $direct->id, 100);
    }

    public function testInvalidSettlementsAreRejected(): void
    {
        $sale = $this->creditSale();

        foreach ([[(int) $sale->id, (int) $sale->id, 10], [(int) $sale->id, 999, 10], [(int) $sale->id, (int) $sale->id, 0]] as [$paying, $settled, $amount]) {
            try {
                $this->kit->settle->execute(LedgerKit::ENTITY, $paying, $settled, $amount);
                $this->fail('The settlement should have been rejected.');
            } catch (Throwable $e) {
                $this->assertInstanceOf(ContabilException::class, $e);
            }
        }
    }

    public function testDraftsAndReversalsCannotTakePartInSettlements(): void
    {
        $sale  = $this->creditSale();
        $draft = $this->kit->create->execute(LedgerKit::ENTITY, $this->kit->entry('2026-03-20', 'Draft', [['1.1.01', 'D', 5], ['1.1.02', 'C', 5]]))->entry;

        try {
            $this->kit->settle->execute(LedgerKit::ENTITY, (int) $draft->id, (int) $sale->id, 5);
            $this->fail('A draft cannot settle.');
        } catch (ConflictException) {
            $this->addToAssertionCount(1);
        }

        $reversal = $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $this->collection(50)->id, 'Wrong')->reversal;

        $this->expectException(ConflictException::class);
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $reversal->id, (int) $sale->id, 5);
    }

    public function testSettledEntryCannotBeReversedUntilItsSettlementsAre(): void
    {
        $sale       = $this->creditSale();
        $collection = $this->collection(400);
        $this->kit->settle->execute(LedgerKit::ENTITY, (int) $collection->id, (int) $sale->id, 400);

        try {
            $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $sale->id, 'Cancel sale');
            $this->fail('The sale has an active settlement.');
        } catch (ConflictException $e) {
            $this->assertStringContainsString('settlement', $e->getMessage());
        }

        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $collection->id, 'Bounced');
        $this->assertSame([], $this->kit->queries->settlementsOf(LedgerKit::ENTITY, (int) $sale->id), 'a reversed settlement entry no longer counts');

        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $sale->id, 'Cancel sale');
        $this->assertSame(EntryStatus::Reversed, $this->kit->queries->get(LedgerKit::ENTITY, (int) $sale->id)->status);
    }

    public function testIntegrityPassesOnAHealthyLedger(): void
    {
        $this->creditSale();
        $original = $this->creditSale(200);
        $this->kit->reverse->execute(LedgerKit::ENTITY, (int) $original->id, 'Mistake');

        $report = $this->kit->integrity->verify(LedgerKit::ENTITY);

        $this->assertTrue($report->isIntact(), json_encode($report->toArray()));
        $this->assertSame(3, $report->checked);
    }

    public function testIntegrityDetectsAnEditedEntry(): void
    {
        $this->creditSale();
        $second = $this->creditSale(200);
        $this->creditSale(300);

        $this->kit->ledger->tamper(new JournalEntry(
            $second->id,
            $second->entityId,
            $second->fiscalYearId,
            $second->number,
            $second->accrualDate,
            $second->cashDate,
            $second->recordedAt,
            'Quietly edited',
            $second->status,
            $second->reversalOfId,
            $second->reversalReason,
            $second->sourceType,
            $second->sourceId,
            $second->idempotencyKey,
            $second->userId,
            $second->hash,
            $second->previousHash,
            $second->chainPosition,
            $second->lines,
        ));

        $report = $this->kit->integrity->verify(LedgerKit::ENTITY);

        $this->assertFalse($report->isIntact());
        $this->assertSame([(int) $second->id], array_unique(array_column($report->toArray()['problems'], 'entry_id')));
    }

    public function testIntegrityDetectsAnEditedEntryEvenWhenItsHashIsRecomputed(): void
    {
        $this->creditSale();
        $second = $this->creditSale(200);
        $third  = $this->creditSale(300);

        $forged = new JournalEntry(
            $second->id,
            $second->entityId,
            $second->fiscalYearId,
            $second->number,
            $second->accrualDate,
            $second->cashDate,
            $second->recordedAt,
            'Forged',
            $second->status,
            $second->reversalOfId,
            $second->reversalReason,
            $second->sourceType,
            $second->sourceId,
            $second->idempotencyKey,
            $second->userId,
            null,
            $second->previousHash,
            $second->chainPosition,
            $second->lines,
        );
        $forged = new JournalEntry(
            $forged->id,
            $forged->entityId,
            $forged->fiscalYearId,
            $forged->number,
            $forged->accrualDate,
            $forged->cashDate,
            $forged->recordedAt,
            $forged->description,
            $forged->status,
            $forged->reversalOfId,
            $forged->reversalReason,
            $forged->sourceType,
            $forged->sourceId,
            $forged->idempotencyKey,
            $forged->userId,
            EntryHasher::hash($forged),
            $forged->previousHash,
            $forged->chainPosition,
            $forged->lines,
        );
        $this->kit->ledger->tamper($forged);

        $report = $this->kit->integrity->verify(LedgerKit::ENTITY);

        $this->assertSame([(int) $third->id], array_unique(array_column($report->toArray()['problems'], 'entry_id')), 'the next entry no longer links to it');
    }

    public function testIntegrityDetectsARemovedEntry(): void
    {
        $this->creditSale();
        $second = $this->creditSale(200);
        $this->creditSale(300);

        $draftOnly  = $this->kit->ledger;
        $reflection = new ReflectionProperty($draftOnly, 'entries');
        $entries    = $reflection->getValue($draftOnly);
        unset($entries[$second->id]);
        $reflection->setValue($draftOnly, $entries);

        $this->assertFalse($this->kit->integrity->verify(LedgerKit::ENTITY)->isIntact());
    }

    public function testPostingRulesPostFromAnEvent(): void
    {
        $rule = $this->kit->rules->register(LedgerKit::ENTITY, 'invoice.issued', $this->kit->ids['1.1.02'], $this->kit->ids['4.1.01']);

        $result = $this->kit->rules->post(LedgerKit::ENTITY, 'invoice.issued', 2500, '2026-03-05', 'Invoice 10', [], ['source_type' => 'invoice', 'source_id' => '10', 'idempotency_key' => 'erp:invoice:10:1']);

        $this->assertNotNull($result);
        $this->assertSame(EntryStatus::Posted, $result->entry->status);
        $this->assertSame($rule->debitAccountId, $result->entry->lines[0]->accountId);
        $this->assertSame(2500, $result->entry->totalCredits());

        $again = $this->kit->rules->post(LedgerKit::ENTITY, 'invoice.issued', 2500, '2026-03-05', 'Invoice 10', [], ['source_type' => 'invoice', 'source_id' => '10', 'idempotency_key' => 'erp:invoice:10:1']);
        $this->assertFalse($again->created);
    }

    public function testPostingRuleConditionsPickTheRule(): void
    {
        $this->kit->rules->register(LedgerKit::ENTITY, 'payment', $this->kit->ids['1.1.01'], $this->kit->ids['1.1.02'], ['kind' => 'collection']);
        $this->kit->rules->register(LedgerKit::ENTITY, 'payment', $this->kit->ids['2.1.01'], $this->kit->ids['1.1.01'], ['kind' => 'supplier']);

        $result = $this->kit->rules->post(LedgerKit::ENTITY, 'payment', 100, '2026-03-05', 'Pay supplier', ['kind' => 'supplier']);

        $this->assertSame($this->kit->ids['2.1.01'], $result->entry->lines[0]->accountId);
    }

    public function testNoMatchingRuleWritesNothing(): void
    {
        $this->kit->rules->register(LedgerKit::ENTITY, 'payment', $this->kit->ids['1.1.01'], $this->kit->ids['1.1.02'], ['kind' => 'collection']);

        $this->assertNull($this->kit->rules->post(LedgerKit::ENTITY, 'payment', 100, '2026-03-05', 'Unknown', ['kind' => 'other']));
        $this->assertNull($this->kit->rules->post(LedgerKit::ENTITY, 'unknown.event', 100, '2026-03-05', 'Unknown'));
        $this->assertSame([], $this->kit->dispatcher->events);
    }

    public function testInactiveRulesAreSkippedAndBadRulesRefused(): void
    {
        $rule = $this->kit->rules->register(LedgerKit::ENTITY, 'x', $this->kit->ids['1.1.01'], $this->kit->ids['4.1.01']);
        $this->kit->rules->setActive(LedgerKit::ENTITY, (int) $rule->id, false);

        $this->assertNull($this->kit->rules->post(LedgerKit::ENTITY, 'x', 1, '2026-03-05', 'x'));

        $this->expectException(ValidationException::class);
        $this->kit->rules->register(LedgerKit::ENTITY, 'y', $this->kit->ids['1'], $this->kit->ids['4.1.01']);
    }
}
