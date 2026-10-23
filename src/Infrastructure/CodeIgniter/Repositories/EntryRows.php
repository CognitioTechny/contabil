<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Maps database rows to entries and lines.
 */
final class EntryRows
{
    /**
     * @param array<string, mixed>       $row
     * @param list<array<string, mixed>> $lineRows
     */
    public static function entry(array $row, array $lineRows): JournalEntry
    {
        return new JournalEntry(
            (int) $row['id'],
            (int) $row['entity_id'],
            self::nullableInt($row['fiscal_year_id']),
            self::nullableInt($row['number']),
            new DateTimeImmutable((string) $row['accrual_date']),
            $row['cash_date'] === null ? null : new DateTimeImmutable((string) $row['cash_date']),
            new DateTimeImmutable((string) $row['recorded_at'], new DateTimeZone('UTC')),
            (string) $row['description'],
            EntryStatus::from((string) $row['status']),
            self::nullableInt($row['reversal_of_id']),
            self::nullableString($row['reversal_reason']),
            self::nullableString($row['source_type']),
            self::nullableString($row['source_id']),
            self::nullableString($row['idempotency_key']),
            self::nullableString($row['user_id']),
            self::nullableString($row['hash']),
            self::nullableString($row['previous_hash']),
            self::nullableInt($row['chain_position']),
            array_map(self::line(...), $lineRows),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function line(array $row): Line
    {
        return new Line(
            (int) $row['account_id'],
            Side::from((string) $row['side']),
            (int) $row['amount'],
            self::nullableString($row['line_description']),
            (int) $row['position'],
            (int) $row['id'],
        );
    }

    /**
     * Header columns of an entry, as stored.
     *
     * @return array<string, mixed>
     */
    public static function header(JournalEntry $entry, DateTimeImmutable $recordedAt): array
    {
        return [
            'entity_id'       => $entry->entityId,
            'fiscal_year_id'  => $entry->fiscalYearId,
            'number'          => $entry->number,
            'accrual_date'    => $entry->accrualDate->format('Y-m-d'),
            'cash_date'       => $entry->cashDate?->format('Y-m-d'),
            'recorded_at'     => ($entry->recordedAt ?? $recordedAt)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            'description'     => $entry->description,
            'status'          => $entry->status->value,
            'reversal_of_id'  => $entry->reversalOfId,
            'reversal_reason' => $entry->reversalReason,
            'source_type'     => $entry->sourceType,
            'source_id'       => $entry->sourceId,
            'idempotency_key' => $entry->idempotencyKey,
            'user_id'         => $entry->userId,
            'hash'            => $entry->hash,
            'previous_hash'   => $entry->previousHash,
            'chain_position'  => $entry->chainPosition,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }
}
