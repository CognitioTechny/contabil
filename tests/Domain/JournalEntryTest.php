<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CodeIgniter\Test\CIUnitTestCase;
use CognitioTechny\Contabil\Domain\Entry\EntryHasher;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * @internal
 */
final class JournalEntryTest extends CIUnitTestCase
{
    private function draft(int $debit = 100, int $credit = 100, string $description = 'Sale'): JournalEntry
    {
        return JournalEntry::draft(1, new DateTimeImmutable('2026-03-05'), $description, [
            new Line(10, Side::Debit, $debit),
            new Line(20, Side::Credit, $credit),
        ]);
    }

    private function posted(?string $previous = null): JournalEntry
    {
        return $this->draft()->withId(7)->posted(1, 1, 1, new DateTimeImmutable('2026-03-05 10:00:00'), $previous);
    }

    public function testDraftNumbersTheLines(): void
    {
        $entry = $this->draft();

        $this->assertSame([1, 2], array_map(static fn (Line $l): int => $l->position, $entry->lines));
        $this->assertSame(EntryStatus::Draft, $entry->status);
        $this->assertTrue($entry->isBalanced());
    }

    public function testDraftNeedsDescriptionAndLines(): void
    {
        $this->expectException(ValidationException::class);
        $this->draft(100, 100, '   ');
    }

    public function testLineAmountMustBePositive(): void
    {
        $this->expectException(ValidationException::class);
        new Line(1, Side::Debit, 0);
    }

    public function testSourceTypeAndIdGoTogether(): void
    {
        $this->expectException(ValidationException::class);
        JournalEntry::draft(1, new DateTimeImmutable('2026-03-05'), 'x', [new Line(1, Side::Debit, 1)], null, 'invoice');
    }

    public function testUnbalancedEntryCannotBePosted(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Debits (100) and credits (90)');
        $this->draft(100, 90)->assertPostable();
    }

    public function testSingleLineCannotBePosted(): void
    {
        $entry = JournalEntry::draft(1, new DateTimeImmutable('2026-03-05'), 'x', [new Line(1, Side::Debit, 5)]);

        $this->expectException(ValidationException::class);
        $entry->assertPostable();
    }

    public function testPostingSealsTheEntryInTheChain(): void
    {
        $posted = $this->posted();

        $this->assertSame(EntryStatus::Posted, $posted->status);
        $this->assertNotNull($posted->hash);
        $this->assertTrue(EntryHasher::matches($posted));
        $this->assertNull($posted->previousHash);
        $this->assertSame(64, strlen((string) $posted->hash));
    }

    public function testHashCoversTheChainLinkAndTheContent(): void
    {
        $first  = $this->posted();
        $second = $this->posted($first->hash);

        $this->assertNotSame($first->hash, $second->hash);

        $tampered = new JournalEntry(
            $first->id,
            $first->entityId,
            $first->fiscalYearId,
            $first->number,
            $first->accrualDate,
            $first->cashDate,
            $first->recordedAt,
            'Edited',
            $first->status,
            $first->reversalOfId,
            $first->reversalReason,
            $first->sourceType,
            $first->sourceId,
            $first->idempotencyKey,
            $first->userId,
            $first->hash,
            $first->previousHash,
            $first->chainPosition,
            $first->lines,
        );

        $this->assertFalse(EntryHasher::matches($tampered));
    }

    public function testReversingStatusKeepsTheHashValid(): void
    {
        $this->assertTrue(EntryHasher::matches($this->posted()->markReversed()));
    }

    public function testPostedEntryCannotBeRevised(): void
    {
        $this->expectException(ConflictException::class);
        $this->posted()->revise(new DateTimeImmutable('2026-03-06'), 'New', [new Line(1, Side::Debit, 1)], null, null, null);
    }

    public function testPostedEntryCannotBePostedAgain(): void
    {
        $this->expectException(ConflictException::class);
        $this->posted()->posted(1, 2, 2, new DateTimeImmutable(), null);
    }

    public function testReversalMirrorsTheLines(): void
    {
        $reversal = $this->posted()->reversal(new DateTimeImmutable('2026-03-10'), 'Wrong customer', 'u1');

        $this->assertSame(7, $reversal->reversalOfId);
        $this->assertSame('Wrong customer', $reversal->reversalReason);
        $this->assertSame([Side::Credit, Side::Debit], array_map(static fn (Line $l): Side => $l->side, $reversal->lines));
        $this->assertSame(100, $reversal->totalDebits());
        $this->assertSame(EntryStatus::Draft, $reversal->status);
    }

    public function testReversalNeedsAReason(): void
    {
        $this->expectException(ValidationException::class);
        $this->posted()->reversal(new DateTimeImmutable('2026-03-10'), ' ', null);
    }

    public function testReversedEntryCannotBeReversedAgain(): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('already been reversed');
        $this->posted()->markReversed()->reversal(new DateTimeImmutable('2026-03-10'), 'again', null);
    }

    public function testReversalCannotBeReversed(): void
    {
        $reversal = $this->posted()->reversal(new DateTimeImmutable('2026-03-10'), 'x', null)->withId(8)
            ->posted(1, 2, 2, new DateTimeImmutable('2026-03-10 10:00:00'), null);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('A reversal cannot be reversed');
        $reversal->reversal(new DateTimeImmutable('2026-03-11'), 'x', null);
    }

    public function testDraftCannotBeReversed(): void
    {
        $this->expectException(ConflictException::class);
        $this->draft()->withId(3)->reversal(new DateTimeImmutable('2026-03-10'), 'x', null);
    }

    public function testCashDateOfAReversalFollowsTheOriginal(): void
    {
        $cash = JournalEntry::draft(1, new DateTimeImmutable('2026-03-05'), 'Paid', [new Line(1, Side::Debit, 5), new Line(2, Side::Credit, 5)], new DateTimeImmutable('2026-03-05'))
            ->withId(9)->posted(1, 1, 1, new DateTimeImmutable('2026-03-05 10:00:00'), null);

        $reversal = $cash->reversal(new DateTimeImmutable('2026-03-12'), 'x', null);

        $this->assertSame('2026-03-12', $reversal->cashDate?->format('Y-m-d'));
        $this->assertNull($this->posted()->reversal(new DateTimeImmutable('2026-03-12'), 'x', null)->cashDate);
    }

    public function testSameContentIgnoresIdentity(): void
    {
        $this->assertTrue($this->draft()->sameContentAs($this->draft()->withId(4)));
        $this->assertFalse($this->draft()->sameContentAs($this->draft(200, 200)));
    }
}
