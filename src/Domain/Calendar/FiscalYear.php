<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Calendar;

use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use DateTimeImmutable;

/**
 * A fiscal year of an entity: twelve monthly periods starting on the first of
 * a month. `OPEN` becomes `CLOSED` only when every period is closed, and a
 * closed year can be reopened.
 */
final class FiscalYear
{
    public function __construct(
        public readonly ?int $id,
        public readonly int $entityId,
        public readonly string $label,
        public readonly DateTimeImmutable $startDate,
        public readonly DateTimeImmutable $endDate,
        public readonly YearStatus $status = YearStatus::Open,
    ) {
    }

    /**
     * Builds a new open year and its twelve gap-free monthly periods.
     *
     * @return array{0: self, 1: list<Period>}
     *
     * @throws ValidationException The start is not the first day of a month, or the label is invalid.
     */
    public static function start(int $entityId, DateTimeImmutable $startDate, ?string $label = null): array
    {
        if ($startDate->format('j') !== '1') {
            throw new ValidationException('A fiscal year must start on the first day of a month.');
        }

        $start = $startDate->setTime(0, 0);
        $end   = $start->modify('+12 months')->modify('-1 day');

        $label = $label === null ? self::defaultLabel($start) : trim($label);

        if ($label === '' || mb_strlen($label) > 50) {
            throw new ValidationException('The fiscal year label is required and has at most 50 characters.');
        }

        $periods = [];

        for ($month = 0; $month < 12; $month++) {
            $first     = $start->modify('+' . $month . ' months');
            $periods[] = new Period(null, null, $first, $first->modify('last day of this month'));
        }

        return [new self(null, $entityId, $label, $start, $end), $periods];
    }

    public function overlaps(self $other): bool
    {
        return $this->startDate <= $other->endDate && $other->startDate <= $this->endDate;
    }

    /**
     * @param iterable<self> $existing Years already stored for the same entity.
     *
     * @throws ConflictException When this year overlaps any of them.
     */
    public function assertNoOverlap(iterable $existing): void
    {
        foreach ($existing as $year) {
            if ($this->id !== null && $year->id === $this->id) {
                continue;
            }

            if ($this->overlaps($year)) {
                throw new ConflictException(sprintf(
                    'The fiscal year %s to %s overlaps "%s" (%s to %s).',
                    $this->startDate->format('Y-m-d'),
                    $this->endDate->format('Y-m-d'),
                    $year->label,
                    $year->startDate->format('Y-m-d'),
                    $year->endDate->format('Y-m-d'),
                ));
            }

            if ($year->label === $this->label) {
                throw new ConflictException(sprintf('The fiscal year label "%s" is already in use.', $this->label));
            }
        }
    }

    /**
     * @param iterable<Period> $periods Every period of this year.
     */
    public function close(iterable $periods): self
    {
        if ($this->status !== YearStatus::Open) {
            throw new ConflictException('The fiscal year is already closed.');
        }

        foreach ($periods as $period) {
            if ($period->status === PeriodStatus::Open) {
                throw new ConflictException(sprintf('Close the period starting %s before closing the year.', $period->startDate->format('Y-m-d')));
            }
        }

        return new self($this->id, $this->entityId, $this->label, $this->startDate, $this->endDate, YearStatus::Closed);
    }

    public function reopen(): self
    {
        if ($this->status !== YearStatus::Closed) {
            throw new ConflictException('Only a closed fiscal year can be reopened.');
        }

        return new self($this->id, $this->entityId, $this->label, $this->startDate, $this->endDate, YearStatus::Open);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->entityId, $this->label, $this->startDate, $this->endDate, $this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'entity_id'  => $this->entityId,
            'label'      => $this->label,
            'start_date' => $this->startDate->format('Y-m-d'),
            'end_date'   => $this->endDate->format('Y-m-d'),
            'status'     => $this->status->value,
        ];
    }

    private static function defaultLabel(DateTimeImmutable $start): string
    {
        return $start->format('n') === '1'
            ? $start->format('Y')
            : $start->format('Y') . '/' . ((int) $start->format('Y') + 1);
    }
}
