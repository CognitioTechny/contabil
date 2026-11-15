<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Tests\Domain;

use CognitioTechny\Contabil\Application\Reports\DimensionFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryHasher;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
final class LineDimensionsTest extends TestCase
{
    public function testLineWithoutDimensionsIsUnchanged(): void
    {
        $line = new Line(10, Side::Debit, 500);

        $this->assertSame([], $line->dimensions);
        $this->assertFalse($line->hasDimension('processo'));
    }

    public function testDimensionsAreNormalizedAndSorted(): void
    {
        $line = new Line(10, Side::Debit, 500, null, 1, null, ['processo' => 42, 'cliente' => ' 7 ']);

        $this->assertSame(['cliente' => '7', 'processo' => '42'], $line->dimensions);
        $this->assertTrue($line->hasDimension('processo'));
        $this->assertTrue($line->hasDimension('processo', '42'));
        $this->assertFalse($line->hasDimension('processo', '43'));
    }

    public function testInvalidTypeIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new Line(10, Side::Debit, 500, null, 1, null, ['Processo Judicial' => '1']);
    }

    public function testEmptyIdentifierIsRejected(): void
    {
        $this->expectException(ValidationException::class);

        new Line(10, Side::Debit, 500, null, 1, null, ['processo' => '']);
    }

    public function testTooManyDimensionsAreRejected(): void
    {
        $this->expectException(ValidationException::class);

        $dimensions = [];

        for ($i = 0; $i <= Line::MAX_DIMENSIONS; $i++) {
            $dimensions['d' . $i] = (string) $i;
        }

        new Line(10, Side::Debit, 500, null, 1, null, $dimensions);
    }

    public function testCopiesKeepTheDimensions(): void
    {
        $line = new Line(10, Side::Debit, 500, 'x', 1, 9, ['caso' => '3']);

        $this->assertSame(['caso' => '3'], $line->withPosition(2)->dimensions);
        $this->assertSame(['caso' => '3'], $line->inverted()->dimensions);
        $this->assertSame(Side::Credit, $line->inverted()->side);
    }

    public function testHashOfAnEntryWithoutDimensionsMatchesTheOriginalFormula(): void
    {
        $entry = $this->sealed([new Line(10, Side::Debit, 500, 'a'), new Line(20, Side::Credit, 500, 'b')]);

        // Schema 1.0.0 canonical form, written out by hand: existing chains must still verify.
        $expected = hash('sha256', (string) json_encode([
            1, 7, 3, 3, '2026-03-05', null, '2026-03-05 10:00:00', 'Sale', null, null, null, null, null, null, 'prev',
            [[1, 10, 'D', 500, 'a'], [2, 20, 'C', 500, 'b']],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->assertSame($expected, EntryHasher::hash($entry));
    }

    public function testDimensionsAreSealedInTheHash(): void
    {
        $plain  = $this->sealed([new Line(10, Side::Debit, 500), new Line(20, Side::Credit, 500)]);
        $tagged = $this->sealed([new Line(10, Side::Debit, 500, null, 1, null, ['processo' => '1']), new Line(20, Side::Credit, 500)]);
        $other  = $this->sealed([new Line(10, Side::Debit, 500, null, 1, null, ['processo' => '2']), new Line(20, Side::Credit, 500)]);

        $this->assertNotSame(EntryHasher::hash($plain), EntryHasher::hash($tagged));
        $this->assertNotSame(EntryHasher::hash($tagged), EntryHasher::hash($other));
    }

    public function testDimensionsJoinTheContentFingerprint(): void
    {
        $date = new DateTimeImmutable('2026-03-05');
        $a    = JournalEntry::draft(1, $date, 'x', [new Line(10, Side::Debit, 5, null, 1, null, ['caso' => '1']), new Line(20, Side::Credit, 5)]);
        $b    = JournalEntry::draft(1, $date, 'x', [new Line(10, Side::Debit, 5, null, 1, null, ['caso' => '2']), new Line(20, Side::Credit, 5)]);
        $c    = JournalEntry::draft(1, $date, 'x', [new Line(10, Side::Debit, 5, null, 1, null, ['caso' => '1']), new Line(20, Side::Credit, 5)]);

        $this->assertFalse($a->sameContentAs($b));
        $this->assertTrue($a->sameContentAs($c));
    }

    public function testFilterFromInput(): void
    {
        $this->assertNull(DimensionFilter::fromInput(null, null));
        $this->assertNull(DimensionFilter::fromInput('', ''));

        $any = DimensionFilter::fromInput('processo', null);
        $this->assertSame(['dimension_type' => 'processo', 'dimension_id' => null], $any?->toArray());

        $one = DimensionFilter::fromInput('processo', 42);
        $this->assertSame('42', $one?->id);
    }

    public function testFilterNeedsATypeForAnIdentifier(): void
    {
        $this->expectException(ValidationException::class);

        DimensionFilter::fromInput(null, '42');
    }

    /**
     * @param list<Line> $lines
     */
    private function sealed(array $lines): JournalEntry
    {
        $numbered = [];

        foreach ($lines as $i => $line) {
            $numbered[] = $line->withPosition($i + 1);
        }

        return new JournalEntry(
            null,
            1,
            7,
            3,
            new DateTimeImmutable('2026-03-05'),
            null,
            new DateTimeImmutable('2026-03-05 10:00:00'),
            'Sale',
            EntryStatus::Posted,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            'prev',
            3,
            $numbered,
        );
    }
}
