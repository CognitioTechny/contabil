<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Calendar;

use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use DateTimeImmutable;

/**
 * A month of a fiscal year. `OPEN` accepts entries, `CLOSED` does not and can
 * be reopened, `LOCKED` is closed for good.
 */
final class Period
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $fiscalYearId,
        public readonly DateTimeImmutable $startDate,
        public readonly DateTimeImmutable $endDate,
        public readonly PeriodStatus $status = PeriodStatus::Open,
    ) {
    }

    public function contains(DateTimeImmutable $date): bool
    {
        $day = $date->format('Y-m-d');

        return $day >= $this->startDate->format('Y-m-d') && $day <= $this->endDate->format('Y-m-d');
    }

    public function close(): self
    {
        if ($this->status !== PeriodStatus::Open) {
            throw new ConflictException(sprintf('Only an open period can be closed; this one is %s.', $this->status->value));
        }

        return $this->withStatus(PeriodStatus::Closed);
    }

    public function reopen(): self
    {
        if ($this->status !== PeriodStatus::Closed) {
            throw new ConflictException(sprintf('Only a closed period can be reopened; this one is %s.', $this->status->value));
        }

        return $this->withStatus(PeriodStatus::Open);
    }

    public function lock(): self
    {
        if ($this->status !== PeriodStatus::Closed) {
            throw new ConflictException(sprintf('Only a closed period can be locked; this one is %s.', $this->status->value));
        }

        return $this->withStatus(PeriodStatus::Locked);
    }

    public function withFiscalYearId(int $fiscalYearId): self
    {
        return new self($this->id, $fiscalYearId, $this->startDate, $this->endDate, $this->status);
    }

    public function withId(int $id): self
    {
        return new self($id, $this->fiscalYearId, $this->startDate, $this->endDate, $this->status);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'fiscal_year_id' => $this->fiscalYearId,
            'start_date'     => $this->startDate->format('Y-m-d'),
            'end_date'       => $this->endDate->format('Y-m-d'),
            'status'         => $this->status->value,
        ];
    }

    private function withStatus(PeriodStatus $status): self
    {
        return new self($this->id, $this->fiscalYearId, $this->startDate, $this->endDate, $status);
    }
}
