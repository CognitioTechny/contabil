<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\Account;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Chart\Nature;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Builds the {@see GeneralLedger}.
 *
 * One account (a leaf, or a group that rolls up its descendants) is read in
 * chronological order and paginated with an opaque cursor. A code range gives
 * one section per leaf account and is not paginated; it is capped at
 * {@see self::RANGE_LIMIT} rows so a report can never exhaust memory.
 */
final class GeneralLedgerBuilder
{
    public const MAX_PAGE    = 1000;
    public const RANGE_LIMIT = 5000;

    public function __construct(
        private readonly EntityGuard $entities,
        private readonly AccountRepositoryInterface $accounts,
        private readonly ReportReaderInterface $reader,
    ) {
    }

    /**
     * @param string|null $cursor Value of `next_cursor` from the previous page.
     */
    public function forAccount(int $entityId, int $accountId, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?string $cursor = null, int $limit = 100): GeneralLedger
    {
        $this->entities->assertExists($entityId);
        $this->assertRange($from, $to);

        if ($limit < 1 || $limit > self::MAX_PAGE) {
            throw new ValidationException(sprintf('The page size is between 1 and %d.', self::MAX_PAGE));
        }

        $tree    = new ChartTree($this->accounts->all($entityId));
        $account = $tree->get($accountId) ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $accountId));
        $after   = $cursor === null ? null : self::decode($cursor);

        [$section, $next] = $this->section($entityId, $account, $tree, $tree->leafIds($accountId), $from, $to, $after, $limit);

        return new GeneralLedger($entityId, $from, $to, [$section], $next);
    }

    public function forRange(int $entityId, string $fromCode, string $toCode, ?DateTimeImmutable $from, ?DateTimeImmutable $to): GeneralLedger
    {
        $this->entities->assertExists($entityId);
        $this->assertRange($from, $to);

        if (strnatcmp($fromCode, $toCode) > 0) {
            throw new ValidationException('The first account code must not come after the last.');
        }

        $sections = [];
        $rows     = 0;
        $tree     = new ChartTree($this->accounts->all($entityId));

        foreach ($tree->walk() as $account) {
            if (! $account->acceptsEntries || strnatcmp($account->code, $fromCode) < 0 || strnatcmp($account->code, $toCode) > 0) {
                continue;
            }

            [$section] = $this->section($entityId, $account, $tree, [(int) $account->id], $from, $to, null, self::RANGE_LIMIT + 1);

            if ($section['rows'] === [] && $section['opening'] === 0) {
                continue;
            }

            $rows += count($section['rows']);

            if ($rows > self::RANGE_LIMIT) {
                throw new ValidationException(sprintf('The range has more than %d rows; narrow the codes or the dates.', self::RANGE_LIMIT));
            }

            $sections[] = $section;
        }

        return new GeneralLedger($entityId, $from, $to, $sections, null);
    }

    /**
     * @param list<int>                             $leafIds
     * @param array{0: string, 1: int, 2: int}|null $after
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    private function section(int $entityId, Account $account, ChartTree $tree, array $leafIds, ?DateTimeImmutable $from, ?DateTimeImmutable $to, ?array $after, int $limit): array
    {
        $sign = $account->nature === Nature::Debit ? 1 : -1;

        [$od, $oc] = $from === null ? [0, 0] : $this->reader->sumsFor($entityId, $leafIds, null, $from->modify('-1 day'));
        $opening   = $sign * ($od - $oc);
        $balance   = $opening;

        if ($after !== null) {
            [$d, $c] = $this->reader->sumsFor($entityId, $leafIds, $from, $to, $after);
            $balance += $sign * ($d - $c);
        }

        $lines = $leafIds === [] ? [] : $this->reader->ledgerLines($entityId, $leafIds, $from, $to, $after, $limit + 1);
        $more  = count($lines) > $limit;
        $lines = array_slice($lines, 0, $limit);
        $rows  = [];

        foreach ($lines as $line) {
            $balance += $sign * $line->signed();
            $rows[] = [
                'date'         => $line->accrualDate->format('Y-m-d'),
                'entry_id'     => $line->entryId,
                'entry_number' => $line->entryNumber,
                'description'  => $line->lineDescription ?? $line->description,
                'account_id'   => $line->accountId,
                'account_code' => $tree->get($line->accountId)?->code ?? '',
                'debit'        => $line->side === Side::Debit ? $line->amount : 0,
                'credit'       => $line->side === Side::Credit ? $line->amount : 0,
                'balance'      => $balance,
            ];
        }

        [$td, $tc] = $leafIds === [] ? [0, 0] : $this->reader->sumsFor($entityId, $leafIds, $from, $to);

        return [[
            'account' => ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'nature' => $account->nature->value],
            'opening' => $opening,
            'rows'    => $rows,
            'debits'  => $td,
            'credits' => $tc,
            'closing' => $opening + $sign * ($td - $tc),
        ], $more && $lines !== [] ? self::encode($lines[array_key_last($lines)]->cursor()) : null];
    }

    private function assertRange(?DateTimeImmutable $from, ?DateTimeImmutable $to): void
    {
        if ($from !== null && $to !== null && $from > $to) {
            throw new ValidationException('The start date must not be after the end date.');
        }
    }

    /**
     * @param array{0: string, 1: int, 2: int} $cursor
     */
    private static function encode(array $cursor): string
    {
        return rtrim(strtr(base64_encode(json_encode($cursor, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private static function decode(string $cursor): array
    {
        $data = json_decode((string) base64_decode(strtr($cursor, '-_', '+/'), true), true);

        if (! is_array($data) || count($data) !== 3 || ! is_string($data[0]) || ! is_int($data[1]) || ! is_int($data[2])
                              || preg_match('/^\d{4}-\d{2}-\d{2}$/', $data[0]) !== 1) {
            throw new ValidationException('The cursor is not valid.');
        }

        return [$data[0], $data[1], $data[2]];
    }
}
