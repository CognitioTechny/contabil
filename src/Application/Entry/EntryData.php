<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Side;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Input of a new or revised entry, as the API and host adapters send it.
 * Amounts are integers in minor units.
 */
final class EntryData
{
    /**
     * @param list<array<string, mixed>> $lines Each with account_id, side (D/C), amount, optional description.
     */
    public function __construct(
        public readonly string $accrualDate,
        public readonly string $description,
        public readonly array $lines,
        public readonly ?string $cashDate = null,
        public readonly ?string $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly ?string $idempotencyKey = null,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ValidationException
     */
    public static function fromArray(array $input): self
    {
        $lines = $input['lines'] ?? null;

        if (! is_array($lines) || ! array_is_list($lines)) {
            throw new ValidationException('lines must be a list.');
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                throw new ValidationException('Each line must be an object.');
            }
        }

        return new self(
            self::text($input, 'accrual_date') ?? '',
            self::text($input, 'description') ?? '',
            $lines,
            self::text($input, 'cash_date'),
            self::text($input, 'source_type'),
            self::text($input, 'source_id'),
            self::text($input, 'idempotency_key'),
        );
    }

    /**
     * @throws ValidationException
     */
    public function toDraft(int $entityId, ?string $userId): JournalEntry
    {
        return JournalEntry::draft(
            $entityId,
            DateInput::parse($this->accrualDate, 'accrual_date'),
            $this->description,
            $this->toLines(),
            DateInput::parseOptional($this->cashDate, 'cash_date'),
            $this->sourceType,
            $this->sourceId,
            $this->idempotencyKey,
            $userId,
        );
    }

    /**
     * @return list<Line>
     *
     * @throws ValidationException
     */
    public function toLines(): array
    {
        $lines = [];

        foreach ($this->lines as $index => $row) {
            $number  = $index + 1;
            $amount  = $row['amount'] ?? null;
            $account = $row['account_id'] ?? null;

            if (! is_int($amount)) {
                throw new ValidationException(sprintf('Line %d: amount must be an integer number of minor units.', $number));
            }

            if (! is_int($account) && ! (is_string($account) && ctype_digit($account))) {
                throw new ValidationException(sprintf('Line %d: account_id must be an integer.', $number));
            }

            $description = $row['description'] ?? null;

            if ($description !== null && ! is_string($description)) {
                throw new ValidationException(sprintf('Line %d: description must be text.', $number));
            }

            try {
                $lines[] = new Line((int) $account, Side::fromInput($row['side'] ?? null), $amount, $description === '' ? null : $description, $number);
            } catch (ValidationException $e) {
                throw new ValidationException(sprintf('Line %d: %s', $number, $e->getMessage()), 0, $e);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function text(array $input, string $key): ?string
    {
        $value = $input[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            throw new ValidationException(sprintf('%s must be text.', $key));
        }

        return $value;
    }
}
