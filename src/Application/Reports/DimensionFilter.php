<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Reports;

use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Restricts a report to the lines that carry an analytic dimension (cost
 * center). Without an identifier it keeps every line tagged with the type;
 * with one, only the lines of that cost center.
 */
final class DimensionFilter
{
    public function __construct(
        public readonly string $type,
        public readonly ?string $id = null,
    ) {
        // Reuses the line rules so a filter can only name what a line can carry.
        Line::normalizeDimensions([$type => $id ?? '-']);
    }

    /**
     * Builds the filter from request parameters; both absent means no filter.
     *
     * @throws ValidationException An identifier without a type, or an invalid type.
     */
    public static function fromInput(mixed $type, mixed $id = null): ?self
    {
        $type = is_string($type) ? trim($type) : null;
        $id   = is_int($id) ? (string) $id : (is_string($id) ? trim($id) : null);

        if ($type === null || $type === '') {
            if ($id !== null && $id !== '') {
                throw new ValidationException('dimension_id needs dimension_type.');
            }

            return null;
        }

        return new self($type, $id === '' ? null : $id);
    }

    /**
     * @return array{dimension_type: string, dimension_id: string|null}
     */
    public function toArray(): array
    {
        return ['dimension_type' => $this->type, 'dimension_id' => $this->id];
    }

    public function label(): string
    {
        return $this->id === null ? $this->type . ' (any)' : $this->type . ' ' . $this->id;
    }
}
