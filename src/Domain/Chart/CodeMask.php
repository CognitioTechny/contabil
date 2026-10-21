<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

use CognitioTechny\Contabil\Domain\Exception\ConfigurationException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Format of account codes, one segment per level of the chart.
 *
 * A mask such as `9.9.99.999` has four segments, so the chart is at most four
 * levels deep, and a level-3 code must look like `1.1.01`. Pattern characters:
 * `9` digit, `A` letter, `X` letter or digit. The separator is the one other
 * character the mask uses (for example `.` or `-`); a mask with a single
 * segment has none. With no mask, any code is accepted at any depth.
 */
final class CodeMask
{
    /**
     * @param list<string> $segments Pattern of each segment, shallowest first.
     */
    private function __construct(private readonly array $segments, private readonly string $separator)
    {
    }

    public static function none(): self
    {
        return new self([], '');
    }

    public static function fromString(?string $mask): self
    {
        $mask = $mask === null ? '' : trim($mask);

        if ($mask === '') {
            return self::none();
        }

        if (preg_match('/^[9AX]+(?:[^9AX\s][9AX]+)*$/', $mask) !== 1) {
            throw new ConfigurationException('The account code mask must be groups of 9, A and X joined by one separator character, for example "9.9.99.999".');
        }

        $separators = array_unique(str_split((string) preg_replace('/[9AX]/', '', $mask)));

        if (count($separators) > 1) {
            throw new ConfigurationException('The account code mask must use a single kind of separator.');
        }

        $separator = $separators[0] ?? '';

        return new self($separator === '' ? [$mask] : explode($separator, $mask), $separator);
    }

    public function isEnforced(): bool
    {
        return $this->segments !== [];
    }

    /**
     * @return int|null Deepest level the mask allows, or null when unrestricted.
     */
    public function maxLevel(): ?int
    {
        return $this->isEnforced() ? count($this->segments) : null;
    }

    /**
     * @throws ValidationException When the code does not fit the mask at that level.
     */
    public function validate(string $code, int $level): void
    {
        if (! $this->isEnforced()) {
            return;
        }

        if ($level > count($this->segments)) {
            throw new ValidationException(sprintf('The chart is limited to %d levels by the code mask.', count($this->segments)));
        }

        $regex = implode(
            preg_quote($this->separator, '/'),
            array_map(
                static fn (string $segment): string => implode('', array_map(
                    static fn (string $char): string => match ($char) {
                        '9'     => '[0-9]',
                        'A'     => '[A-Za-z]',
                        default => '[A-Za-z0-9]',
                    },
                    str_split($segment),
                )),
                array_slice($this->segments, 0, $level),
            ),
        );

        if (preg_match('/^' . $regex . '$/D', $code) !== 1) {
            throw new ValidationException(sprintf(
                'The code "%s" does not match the mask "%s" for level %d.',
                $code,
                implode($this->separator, array_slice($this->segments, 0, $level)),
                $level,
            ));
        }
    }
}
