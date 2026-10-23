<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Builds the Journal and the statement by source. Both are raw: they list what
 * is in the ledger and ignore the basis.
 */
final class EntryListingBuilder
{
    public const MAX_PAGE = 500;

    public function __construct(
        private readonly EntityGuard $entities,
        private readonly AccountRepositoryInterface $accounts,
        private readonly ReportReaderInterface $reader,
    ) {
    }

    /**
     * Chronological journal: by accrual date, then entry number. Paginated with
     * an opaque cursor taken from `next_cursor`.
     */
    public function journal(int $entityId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?string $cursor = null, int $limit = 100): EntryListing
    {
        $this->entities->assertExists($entityId);

        if ($from !== null && $to !== null && $from > $to) {
            throw new ValidationException('The start date must not be after the end date.');
        }

        if ($limit < 1 || $limit > self::MAX_PAGE) {
            throw new ValidationException(sprintf('The page size is between 1 and %d.', self::MAX_PAGE));
        }

        $entries = $this->reader->journal($entityId, $from, $to, $cursor === null ? null : self::decode($cursor), $limit + 1);
        $more    = count($entries) > $limit;
        $entries = array_slice($entries, 0, $limit);
        $next    = $more && $entries !== [] ? self::encode($entries[array_key_last($entries)]) : null;

        return $this->listing('journal', 'Journal', $entityId, $entries, [
            'Period' => ($from?->format('Y-m-d') ?? 'beginning') . ' to ' . ($to?->format('Y-m-d') ?? 'end'),
        ], $next);
    }

    /**
     * Every entry of one host document, reversals included, in posting order.
     */
    public function bySource(int $entityId, string $sourceType, string $sourceId): EntryListing
    {
        $this->entities->assertExists($entityId);

        if ($sourceType === '' || $sourceId === '') {
            throw new ValidationException('The source type and the source id are required.');
        }

        return $this->listing('statement-by-source', 'Statement by source', $entityId, $this->reader->bySource($entityId, $sourceType, $sourceId), [
            'Source' => $sourceType . ' #' . $sourceId,
        ]);
    }

    /**
     * @param list<JournalEntry>    $entries
     * @param array<string, string> $summary
     */
    private function listing(string $slug, string $title, int $entityId, array $entries, array $summary, ?string $next = null): EntryListing
    {
        $tree  = new ChartTree($this->accounts->all($entityId));
        $total = 0;
        $rows  = [];

        foreach ($entries as $entry) {
            $array          = $entry->toArray();
            $array['lines'] = array_map(static function (array $line) use ($tree): array {
                $account = $tree->get($line['account_id']);

                return $line + ['account_code' => $account->code ?? '', 'account_name' => $account->name ?? ''];
            }, $array['lines']);

            $total += $entry->totalDebits();
            $rows[] = $array;
        }

        return new EntryListing($slug, $title, $entityId, $rows, $summary, $next, $total);
    }

    private static function encode(JournalEntry $last): string
    {
        return rtrim(strtr(base64_encode(json_encode([$last->accrualDate->format('Y-m-d'), $last->number], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function decode(string $cursor): array
    {
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($data) || count($data) !== 2 || ! is_string($data[0]) || ! is_int($data[1])
                              || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[0]) !== 1) {
            throw new ValidationException('The cursor is not valid.');
        }

        return [$data[0], $data[1]];
    }
}
