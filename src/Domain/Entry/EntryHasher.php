<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

/**
 * Seals an entry in the per-entity hash chain. The hash covers everything that
 * never changes after posting plus the previous hash, so editing any posted
 * row, or removing one, breaks the chain. The status is left out on purpose:
 * `POSTED` becomes `REVERSED` without touching the content.
 */
final class EntryHasher
{
    public const ALGORITHM = 'sha256';

    public static function hash(JournalEntry $entry): string
    {
        return hash(self::ALGORITHM, self::canonical($entry));
    }

    /**
     * Whether the stored hash matches the content and the chain link.
     */
    public static function matches(JournalEntry $entry): bool
    {
        return $entry->hash !== null && hash_equals($entry->hash, self::hash($entry));
    }

    private static function canonical(JournalEntry $entry): string
    {
        return json_encode([
            $entry->entityId,
            $entry->fiscalYearId,
            $entry->number,
            $entry->chainPosition,
            $entry->accrualDate->format('Y-m-d'),
            $entry->cashDate?->format('Y-m-d'),
            $entry->recordedAt?->format('Y-m-d H:i:s'),
            $entry->description,
            $entry->reversalOfId,
            $entry->reversalReason,
            $entry->sourceType,
            $entry->sourceId,
            $entry->idempotencyKey,
            $entry->userId,
            $entry->previousHash,
            array_map(
                static fn (Line $line): array => [$line->position, $line->accountId, $line->side->value, $line->amount, $line->description],
                $entry->lines,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
