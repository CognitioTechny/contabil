<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Support;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * Parses the `YYYY-MM-DD` dates that cross the API boundary.
 */
final class DateInput
{
    /**
     * @throws ValidationException
     */
    public static function parse(mixed $value, string $field): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;

        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException(sprintf('%s must be a valid date as YYYY-MM-DD.', $field));
        }

        return $date;
    }

    public static function parseOptional(mixed $value, string $field): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : self::parse($value, $field);
    }
}
