<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain;

use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * An amount of money as an integer number of minor units (cents). Never a
 * float. The currency is a property of the entity, not of the amount.
 */
final class Money
{
    private function __construct(public readonly int $minor)
    {
    }

    public static function of(int $minor): self
    {
        return new self($minor);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /**
     * Parses a plain decimal string such as `1234.56` or `-0.5` without ever
     * going through a float. Used by host adapters converting from `DECIMAL`.
     *
     * @throws ValidationException When the text is not a decimal number or has more digits than the scale.
     */
    public static function fromDecimal(string $decimal, int $scale = 2): self
    {
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim($decimal), $m) !== 1) {
            throw new ValidationException(sprintf('"%s" is not a decimal amount.', $decimal));
        }

        $fraction = $m[3] ?? '';

        if (strlen($fraction) > $scale) {
            if (trim(substr($fraction, $scale), '0') !== '') {
                throw new ValidationException(sprintf('"%s" has more than %d decimal places.', $decimal, $scale));
            }

            $fraction = substr($fraction, 0, $scale);
        }

        $digits = ltrim($m[2] . str_pad($fraction, $scale, '0'), '0');

        if ($digits === '') {
            return new self(0);
        }

        if (strlen($digits) > 18) {
            throw new ValidationException(sprintf('"%s" is too large.', $decimal));
        }

        $value = (int) $digits;

        return new self($m[1] === '-' ? -$value : $value);
    }

    /**
     * Formats minor units as a decimal string, for example `-1234.50`. Zero is
     * always `0.00`, never `-0.00`.
     */
    public static function format(int $minor, int $scale = 2): string
    {
        if ($scale === 0) {
            return (string) $minor;
        }

        $sign   = $minor < 0 ? '-' : '';
        $digits = str_pad((string) abs($minor), $scale + 1, '0', STR_PAD_LEFT);

        return $sign . substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
    }

    public function toDecimal(int $scale = 2): string
    {
        return self::format($this->minor, $scale);
    }

    public function add(self $other): self
    {
        return new self(self::checked($this->minor + $other->minor));
    }

    public function subtract(self $other): self
    {
        return new self(self::checked($this->minor - $other->minor));
    }

    public function negate(): self
    {
        return new self(-$this->minor);
    }

    /**
     * `$this * numerator / denominator`, rounded half away from zero.
     */
    public function ratio(int $numerator, int $denominator): self
    {
        return new self(self::proportion($this->minor, $numerator, $denominator));
    }

    public function isZero(): bool
    {
        return $this->minor === 0;
    }

    public function isPositive(): bool
    {
        return $this->minor > 0;
    }

    public function isNegative(): bool
    {
        return $this->minor < 0;
    }

    public function equals(self $other): bool
    {
        return $this->minor === $other->minor;
    }

    public function compareTo(self $other): int
    {
        return $this->minor <=> $other->minor;
    }

    /**
     * `amount * numerator / denominator` in integers, rounded half away from
     * zero, without an intermediate float.
     */
    public static function proportion(int $amount, int $numerator, int $denominator): int
    {
        if ($denominator <= 0 || $numerator < 0) {
            throw new ValidationException('A proportion needs a positive denominator and a non-negative numerator.');
        }

        $sign   = $amount < 0 ? -1 : 1;
        $amount = abs($amount);

        // amount * numerator / denominator = whole * numerator + (rest * numerator) / denominator
        $whole = intdiv($amount, $denominator);
        $rest  = $amount % $denominator;

        if ($numerator !== 0 && ($whole > intdiv(PHP_INT_MAX, $numerator) || $rest > intdiv(PHP_INT_MAX, $numerator))) {
            throw new ValidationException('The amount is too large to apportion.');
        }

        $scaled = $rest * $numerator;
        $result = $whole * $numerator + intdiv($scaled, $denominator);

        if (($scaled % $denominator) * 2 >= $denominator) {
            $result++;
        }

        return $sign * $result;
    }

    private static function checked(float|int $value): int
    {
        if (is_float($value)) {
            throw new ValidationException('The amount overflows the supported range.');
        }

        return $value;
    }
}
