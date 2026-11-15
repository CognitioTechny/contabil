<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Entry;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * One debit or credit of a journal entry. The amount is in minor units and
 * always positive; the sign comes from the side.
 *
 * A line may carry analytic dimensions (cost centers): a map of dimension type
 * to an opaque host identifier, such as `['processo' => '42', 'cliente' => '7']`.
 * Dimensions are optional; a line without them behaves exactly as before.
 */
final class Line
{
    /**
     * Maximum number of dimensions on one line.
     */
    public const MAX_DIMENSIONS = 10;

    /**
     * @var array<string, string> Dimension type => identifier, sorted by type.
     */
    public readonly array $dimensions;

    /**
     * @param array<array-key, mixed> $dimensions Dimension type => identifier.
     */
    public function __construct(
        public readonly int $accountId,
        public readonly Side $side,
        public readonly int $amount,
        public readonly ?string $description = null,
        public readonly int $position = 1,
        public readonly ?int $id = null,
        array $dimensions = [],
    ) {
        if ($accountId < 1) {
            throw new ValidationException('A line needs an account.');
        }

        if ($amount < 1) {
            throw new ValidationException('The amount of a line must be a positive integer in minor units.');
        }

        if ($description !== null && mb_strlen($description) > 500) {
            throw new ValidationException('The description of a line has at most 500 characters.');
        }

        $this->dimensions = self::normalizeDimensions($dimensions);
    }

    /**
     * Validates and normalizes a dimension map: types are lowercase slugs,
     * identifiers are non-empty text up to 100 characters. Sorted by type so
     * the same set always hashes the same way.
     *
     * @param array<array-key, mixed> $dimensions
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function normalizeDimensions(array $dimensions): array
    {
        if (count($dimensions) > self::MAX_DIMENSIONS) {
            throw new ValidationException(sprintf('A line has at most %d dimensions.', self::MAX_DIMENSIONS));
        }

        $normalized = [];

        foreach ($dimensions as $type => $id) {
            if (! is_string($type) || preg_match('/^[a-z][a-z0-9_]{0,49}$/', $type) !== 1) {
                throw new ValidationException('A dimension type is a lowercase slug of up to 50 characters (letters, digits, underscore).');
            }

            if (is_int($id) && $id > 0) {
                $id = (string) $id;
            }

            if (! is_string($id) || trim($id) === '' || mb_strlen($id) > 100) {
                throw new ValidationException(sprintf('The identifier of dimension "%s" must be non-empty text of up to 100 characters.', $type));
            }

            $normalized[$type] = trim($id);
        }

        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    public function withPosition(int $position): self
    {
        return new self($this->accountId, $this->side, $this->amount, $this->description, $position, $this->id, $this->dimensions);
    }

    public function inverted(): self
    {
        return new self($this->accountId, $this->side->opposite(), $this->amount, $this->description, $this->position, null, $this->dimensions);
    }

    /**
     * Whether the line carries the given dimension (and identifier, when given).
     */
    public function hasDimension(string $type, ?string $id = null): bool
    {
        if (! array_key_exists($type, $this->dimensions)) {
            return false;
        }

        return $id === null || $this->dimensions[$type] === $id;
    }

    /**
     * Amount with debit positive and credit negative.
     */
    public function signed(): int
    {
        return $this->side === Side::Debit ? $this->amount : -$this->amount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'account_id'  => $this->accountId,
            'side'        => $this->side->value,
            'amount'      => $this->amount,
            'description' => $this->description,
            'position'    => $this->position,
            'dimensions'  => $this->dimensions === [] ? (object) [] : $this->dimensions,
        ];
    }
}
