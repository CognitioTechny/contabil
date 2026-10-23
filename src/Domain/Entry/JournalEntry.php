<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A journal entry: a header and N lines. Immutable; every change returns a new
 * instance. Only a draft can change; posting freezes the content, and the only
 * later transition is `POSTED` to `REVERSED`.
 */
final class JournalEntry
{
    /**
     * @param list<Line> $lines
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $entityId,
        public readonly ?int $fiscalYearId,
        public readonly ?int $number,
        public readonly DateTimeImmutable $accrualDate,
        public readonly ?DateTimeImmutable $cashDate,
        public readonly ?DateTimeImmutable $recordedAt,
        public readonly string $description,
        public readonly EntryStatus $status,
        public readonly ?int $reversalOfId,
        public readonly ?string $reversalReason,
        public readonly ?string $sourceType,
        public readonly ?string $sourceId,
        public readonly ?string $idempotencyKey,
        public readonly ?string $userId,
        public readonly ?string $hash,
        public readonly ?string $previousHash,
        public readonly ?int $chainPosition,
        public readonly array $lines,
    ) {
    }

    /**
     * Builds a new draft.
     *
     * @param list<Line> $lines
     *
     * @throws ValidationException
     */
    public static function draft(
        int $entityId,
        DateTimeImmutable $accrualDate,
        string $description,
        array $lines,
        ?DateTimeImmutable $cashDate = null,
        ?string $sourceType = null,
        ?string $sourceId = null,
        ?string $idempotencyKey = null,
        ?string $userId = null,
    ): self {
        $description = trim($description);

        if ($description === '' || mb_strlen($description) > 500) {
            throw new ValidationException('The description is required and has at most 500 characters.');
        }

        if (($sourceType === null) !== ($sourceId === null)) {
            throw new ValidationException('The source type and the source id go together.');
        }

        if ($sourceType !== null && (strlen($sourceType) > 100 || strlen((string) $sourceId) > 100 || $sourceType === '' || $sourceId === '')) {
            throw new ValidationException('The source type and the source id have 1 to 100 characters.');
        }

        if ($idempotencyKey !== null && ($idempotencyKey === '' || strlen($idempotencyKey) > 191)) {
            throw new ValidationException('The idempotency key has 1 to 191 characters.');
        }

        if ($lines === []) {
            throw new ValidationException('An entry needs at least one line.');
        }

        return new self(
            null,
            $entityId,
            null,
            null,
            $accrualDate->setTime(0, 0),
            $cashDate?->setTime(0, 0),
            null,
            $description,
            EntryStatus::Draft,
            null,
            null,
            $sourceType,
            $sourceId,
            $idempotencyKey,
            $userId,
            null,
            null,
            null,
            self::numbered($lines),
        );
    }

    public function totalDebits(): int
    {
        return $this->total(Side::Debit);
    }

    public function totalCredits(): int
    {
        return $this->total(Side::Credit);
    }

    public function isBalanced(): bool
    {
        return $this->totalDebits() === $this->totalCredits();
    }

    public function isReversal(): bool
    {
        return $this->reversalOfId !== null;
    }

    /**
     * Whether another entry carries the same business content (dates,
     * description, source and lines). Used to tell an idempotent retry from a
     * reused key.
     */
    public function sameContentAs(self $other): bool
    {
        return $this->contentFingerprint() === $other->contentFingerprint();
    }

    /**
     * Rules that must hold before an entry enters the ledger.
     *
     * @throws ConflictException   The entry is not a draft.
     * @throws ValidationException Fewer than two lines, or debits differ from credits.
     */
    public function assertPostable(): void
    {
        if ($this->status !== EntryStatus::Draft) {
            throw new ConflictException(sprintf('Only a draft can be posted; this entry is %s.', $this->status->value));
        }

        if (count($this->lines) < 2) {
            throw new ValidationException('An entry needs at least two lines.');
        }

        if (! $this->isBalanced()) {
            throw new ValidationException(sprintf(
                'Debits (%d) and credits (%d) must be equal, in minor units.',
                $this->totalDebits(),
                $this->totalCredits(),
            ));
        }
    }

    /**
     * Replaces the content of a draft, keeping its identity.
     *
     * @param list<Line> $lines
     */
    public function revise(DateTimeImmutable $accrualDate, string $description, array $lines, ?DateTimeImmutable $cashDate, ?string $sourceType, ?string $sourceId): self
    {
        $this->assertDraft();

        $revised = self::draft($this->entityId, $accrualDate, $description, $lines, $cashDate, $sourceType, $sourceId, $this->idempotencyKey, $this->userId);

        return new self(
            $this->id,
            $this->entityId,
            null,
            null,
            $revised->accrualDate,
            $revised->cashDate,
            null,
            $revised->description,
            EntryStatus::Draft,
            null,
            null,
            $revised->sourceType,
            $revised->sourceId,
            $this->idempotencyKey,
            $this->userId,
            null,
            null,
            null,
            $revised->lines,
        );
    }

    /**
     * The posted form of this draft, sealed in the entity's hash chain.
     */
    public function posted(int $fiscalYearId, int $number, int $chainPosition, DateTimeImmutable $recordedAt, ?string $previousHash): self
    {
        $this->assertPostable();

        $sealed = new self(
            $this->id,
            $this->entityId,
            $fiscalYearId,
            $number,
            $this->accrualDate,
            $this->cashDate,
            $recordedAt->setTimezone(new DateTimeZone('UTC')),
            $this->description,
            EntryStatus::Posted,
            $this->reversalOfId,
            $this->reversalReason,
            $this->sourceType,
            $this->sourceId,
            $this->idempotencyKey,
            $this->userId,
            null,
            $previousHash,
            $chainPosition,
            $this->lines,
        );

        return $sealed->withHash(EntryHasher::hash($sealed));
    }

    public function markReversed(): self
    {
        if ($this->status !== EntryStatus::Posted) {
            throw new ConflictException(
                $this->status === EntryStatus::Reversed
                    ? 'The entry has already been reversed.'
                    : 'Only a posted entry can be reversed.',
            );
        }

        return $this->withStatus(EntryStatus::Reversed);
    }

    /**
     * Draft of the entry that cancels this one: same lines on the opposite
     * sides, linked back through `reversal_of_id`.
     *
     * @throws ConflictException   The entry is not posted, or is itself a reversal.
     * @throws ValidationException The reason is empty.
     */
    public function reversal(DateTimeImmutable $date, string $reason, ?string $userId): self
    {
        if ($this->status === EntryStatus::Reversed) {
            throw new ConflictException('The entry has already been reversed.');
        }

        if ($this->status !== EntryStatus::Posted || $this->id === null) {
            throw new ConflictException('Only a posted entry can be reversed.');
        }

        if ($this->isReversal()) {
            throw new ConflictException('A reversal cannot be reversed; post a new entry instead.');
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new ValidationException('The reason of a reversal is required and has at most 500 characters.');
        }

        $draft = self::draft(
            $this->entityId,
            $date,
            mb_substr('Reversal of entry ' . $this->number . ': ' . $this->description, 0, 500),
            array_map(static fn (Line $line): Line => $line->inverted(), $this->lines),
            $this->cashDate === null ? null : $date,
            $this->sourceType,
            $this->sourceId,
            null,
            $userId,
        );

        return new self(
            null,
            $draft->entityId,
            null,
            null,
            $draft->accrualDate,
            $draft->cashDate,
            null,
            $draft->description,
            EntryStatus::Draft,
            $this->id,
            $reason,
            $draft->sourceType,
            $draft->sourceId,
            null,
            $userId,
            null,
            null,
            null,
            $draft->lines,
        );
    }

    public function withId(int $id): self
    {
        return $this->copy(['id' => $id]);
    }

    public function withLines(array $lines): self
    {
        return $this->copy(['lines' => $lines]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'              => $this->id,
            'entity_id'       => $this->entityId,
            'fiscal_year_id'  => $this->fiscalYearId,
            'number'          => $this->number,
            'accrual_date'    => $this->accrualDate->format('Y-m-d'),
            'cash_date'       => $this->cashDate?->format('Y-m-d'),
            'recorded_at'     => $this->recordedAt?->format('Y-m-d H:i:s'),
            'description'     => $this->description,
            'status'          => $this->status->value,
            'reversal_of_id'  => $this->reversalOfId,
            'reversal_reason' => $this->reversalReason,
            'source_type'     => $this->sourceType,
            'source_id'       => $this->sourceId,
            'idempotency_key' => $this->idempotencyKey,
            'user_id'         => $this->userId,
            'hash'            => $this->hash,
            'previous_hash'   => $this->previousHash,
            'total'           => $this->totalDebits(),
            'lines'           => array_map(static fn (Line $line): array => $line->toArray(), $this->lines),
        ];
    }

    private function withHash(string $hash): self
    {
        return $this->copy(['hash' => $hash]);
    }

    private function withStatus(EntryStatus $status): self
    {
        return $this->copy(['status' => $status]);
    }

    private function assertDraft(): void
    {
        if ($this->status !== EntryStatus::Draft) {
            throw new ConflictException(sprintf('A %s entry cannot be changed.', $this->status->value));
        }
    }

    private function total(Side $side): int
    {
        $sum = 0;

        foreach ($this->lines as $line) {
            if ($line->side === $side) {
                $sum += $line->amount;
            }
        }

        return $sum;
    }

    private function contentFingerprint(): string
    {
        return json_encode([
            $this->accrualDate->format('Y-m-d'),
            $this->cashDate?->format('Y-m-d'),
            $this->description,
            $this->sourceType,
            $this->sourceId,
            array_map(static fn (Line $l): array => [$l->accountId, $l->side->value, $l->amount, $l->description], $this->lines),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param list<Line> $lines
     *
     * @return list<Line>
     */
    private static function numbered(array $lines): array
    {
        $numbered = [];

        foreach (array_values($lines) as $index => $line) {
            $numbered[] = $line->withPosition($index + 1);
        }

        return $numbered;
    }

    /**
     * @param array<string, mixed> $changes Property name => new value.
     */
    private function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
