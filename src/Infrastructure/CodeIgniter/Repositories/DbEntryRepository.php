<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Repositories;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CognitioTechny\Contabil\Application\Configuration\TableNames;
use CognitioTechny\Contabil\Application\Ports\ClockInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Entry\Line;
use CognitioTechny\Contabil\Domain\Entry\Settlement;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use DateTimeImmutable;
use Generator;

final class DbEntryRepository implements EntryRepositoryInterface
{
    private const CHAIN_BATCH = 500;

    public function __construct(
        private readonly BaseConnection $db,
        private readonly TableNames $tables,
        private readonly ClockInterface $clock,
    ) {
    }

    public function find(int $entityId, int $id): ?JournalEntry
    {
        $row = $this->entries()->where('entity_id', $entityId)->where('id', $id)->get()->getRowArray();

        return $row === null ? null : $this->load([$row])[0];
    }

    public function findByIdempotencyKey(int $entityId, string $key): ?JournalEntry
    {
        $row = $this->entries()->where('entity_id', $entityId)->where('idempotency_key', $key)->get()->getRowArray();

        return $row === null ? null : $this->load([$row])[0];
    }

    public function reversalOf(int $entityId, int $entryId): ?JournalEntry
    {
        $row = $this->entries()->where('entity_id', $entityId)->where('reversal_of_id', $entryId)->get()->getRowArray();

        return $row === null ? null : $this->load([$row])[0];
    }

    public function search(int $entityId, EntryFilter $filter, ?int $beforeId, int $limit): array
    {
        $builder = $this->entries()->where('entity_id', $entityId);

        if ($filter->status !== null) {
            $builder->where('status', $filter->status->value);
        }

        if ($filter->from !== null) {
            $builder->where('accrual_date >=', $filter->from->format('Y-m-d'));
        }

        if ($filter->to !== null) {
            $builder->where('accrual_date <=', $filter->to->format('Y-m-d'));
        }

        if ($filter->sourceType !== null) {
            $builder->where('source_type', $filter->sourceType);
        }

        if ($filter->sourceId !== null) {
            $builder->where('source_id', $filter->sourceId);
        }

        if ($filter->accountId !== null) {
            $builder->whereIn('id', fn (BaseBuilder $sub): BaseBuilder => $sub->select('entry_id')->from($this->tables->name('entry_lines'))->where('account_id', $filter->accountId));
        }

        if ($beforeId !== null) {
            $builder->where('id <', $beforeId);
        }

        return $this->load($builder->orderBy('id', 'DESC')->limit($limit)->get()->getResultArray());
    }

    public function insert(JournalEntry $draft): JournalEntry
    {
        $this->entries()->insert(EntryRows::header($draft, $this->clock->now()));

        $id = (int) $this->db->insertID();

        $this->insertLines($id, $draft->lines);

        return $this->find($draft->entityId, $id) ?? $draft->withId($id);
    }

    public function replaceDraft(JournalEntry $draft): JournalEntry
    {
        $header = EntryRows::header($draft, $this->clock->now());

        // Identity and creation facts of a draft do not change.
        unset($header['entity_id'], $header['recorded_at'], $header['idempotency_key'], $header['user_id'], $header['status']);

        $draftRow = fn (): BaseBuilder => $this->entries()
            ->where('entity_id', $draft->entityId)
            ->where('id', $draft->id)
            ->where('status', EntryStatus::Draft->value);

        // Not decided from affectedRows(): MySQL reports 0 when nothing changed.
        if ($draftRow()->countAllResults() !== 1) {
            throw new ConflictException('Only a draft can be changed.');
        }

        $draftRow()->update($header);

        $this->lines()->where('entry_id', $draft->id)->delete();
        $this->insertLines((int) $draft->id, $draft->lines);

        return $this->find($draft->entityId, (int) $draft->id) ?? $draft;
    }

    public function deleteDraft(int $entityId, int $id): void
    {
        $exists = $this->entries()->where('entity_id', $entityId)->where('id', $id)->where('status', EntryStatus::Draft->value)->countAllResults() === 1;

        if (! $exists) {
            throw new ConflictException('Only a draft can be deleted.');
        }

        $this->lines()->where('entry_id', $id)->delete();
        $this->entries()->where('id', $id)->where('status', EntryStatus::Draft->value)->delete();
    }

    public function countDrafts(int $entityId, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return $this->entries()
            ->where('entity_id', $entityId)
            ->where('status', EntryStatus::Draft->value)
            ->where('accrual_date >=', $from->format('Y-m-d'))
            ->where('accrual_date <=', $to->format('Y-m-d'))
            ->countAllResults();
    }

    public function lockEntity(int $entityId): void
    {
        // A write that changes nothing still takes the row lock on MySQL and
        // the database write lock on SQLite; both are held until the
        // transaction ends, which serializes postings of one entity.
        $this->db->table($this->tables->name('entities'))
            ->set('updated_at', 'updated_at', false)
            ->where('id', $entityId)
            ->update();
    }

    public function nextNumber(int $entityId, int $fiscalYearId): int
    {
        $sequences = fn (): BaseBuilder => $this->db->table($this->tables->name('sequences'))
            ->where('entity_id', $entityId)
            ->where('fiscal_year_id', $fiscalYearId);

        if ($sequences()->countAllResults() === 0) {
            $this->db->table($this->tables->name('sequences'))->insert([
                'entity_id'      => $entityId,
                'fiscal_year_id' => $fiscalYearId,
                'last_number'    => 0,
            ]);
        }

        $sequences()->set('last_number', 'last_number + 1', false)->update();

        return (int) $sequences()->select('last_number')->get()->getRow('last_number');
    }

    public function nextChainLink(int $entityId): array
    {
        $last = $this->entries()
            ->select('chain_position, hash')
            ->where('entity_id', $entityId)
            ->where('chain_position IS NOT NULL')
            ->orderBy('chain_position', 'DESC')
            ->limit(1)
            ->get()
            ->getRowArray();

        return $last === null ? [1, null] : [(int) $last['chain_position'] + 1, (string) $last['hash']];
    }

    public function savePosted(JournalEntry $posted): JournalEntry
    {
        $this->entries()
            ->where('entity_id', $posted->entityId)
            ->where('id', $posted->id)
            ->where('status', EntryStatus::Draft->value)
            ->update([
                'fiscal_year_id' => $posted->fiscalYearId,
                'number'         => $posted->number,
                'recorded_at'    => $posted->recordedAt?->format('Y-m-d H:i:s'),
                'status'         => EntryStatus::Posted->value,
                'hash'           => $posted->hash,
                'previous_hash'  => $posted->previousHash,
                'chain_position' => $posted->chainPosition,
            ]);

        if ($this->db->affectedRows() !== 1) {
            throw new ConflictException('Only a draft can be posted.');
        }

        return $posted;
    }

    public function markReversed(int $entityId, int $id): void
    {
        $this->entries()
            ->where('entity_id', $entityId)
            ->where('id', $id)
            ->where('status', EntryStatus::Posted->value)
            ->update(['status' => EntryStatus::Reversed->value]);

        if ($this->db->affectedRows() !== 1) {
            throw new ConflictException('Only a posted entry can be reversed.');
        }
    }

    public function insertSettlement(Settlement $settlement): Settlement
    {
        $this->db->table($this->tables->name('settlements'))->insert([
            'settlement_entry_id' => $settlement->settlementEntryId,
            'settled_entry_id'    => $settlement->settledEntryId,
            'amount'              => $settlement->amount,
            'date'                => $settlement->date->format('Y-m-d'),
        ]);

        return $settlement->withId((int) $this->db->insertID());
    }

    public function activeSettlementsOf(int $settledEntryId): array
    {
        $rows = $this->db->table($this->tables->name('settlements') . ' s')
            ->select('s.id, s.settlement_entry_id, s.settled_entry_id, s.amount, s.date')
            ->join($this->tables->name('entries') . ' e', 'e.id = s.settlement_entry_id')
            ->where('s.settled_entry_id', $settledEntryId)
            ->where('e.status <>', EntryStatus::Reversed->value)
            ->orderBy('s.date', 'ASC')
            ->orderBy('s.id', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): Settlement => new Settlement(
            (int) $row['id'],
            (int) $row['settlement_entry_id'],
            (int) $row['settled_entry_id'],
            (int) $row['amount'],
            new DateTimeImmutable((string) $row['date']),
        ), $rows);
    }

    public function amountSettledBy(int $settlementEntryId): int
    {
        $row = $this->db->table($this->tables->name('settlements'))
            ->selectSum('amount', 'total')
            ->where('settlement_entry_id', $settlementEntryId)
            ->get()
            ->getRowArray();

        return (int) ($row['total'] ?? 0);
    }

    public function chain(int $entityId): iterable
    {
        return $this->walkChain($entityId);
    }

    /**
     * @return Generator<int, JournalEntry>
     */
    private function walkChain(int $entityId): Generator
    {
        $after = 0;

        while (true) {
            $rows = $this->entries()
                ->where('entity_id', $entityId)
                ->where('chain_position >', $after)
                ->orderBy('chain_position', 'ASC')
                ->limit(self::CHAIN_BATCH)
                ->get()
                ->getResultArray();

            if ($rows === []) {
                return;
            }

            foreach ($this->load($rows) as $entry) {
                yield $entry;
            }

            $after = (int) $rows[array_key_last($rows)]['chain_position'];
        }
    }

    /**
     * Hydrates entries with their lines using one query for all the lines.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<JournalEntry>
     */
    private function load(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $byEntry = [];

        $lineRows = $this->lines()
            ->whereIn('entry_id', array_map(static fn (array $row): int => (int) $row['id'], $rows))
            ->orderBy('entry_id', 'ASC')
            ->orderBy('position', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($lineRows as $lineRow) {
            $byEntry[(int) $lineRow['entry_id']][] = $lineRow;
        }

        return array_map(static fn (array $row): JournalEntry => EntryRows::entry($row, $byEntry[(int) $row['id']] ?? []), $rows);
    }

    /**
     * @param list<Line> $lines
     */
    private function insertLines(int $entryId, array $lines): void
    {
        $this->lines()->insertBatch(array_map(static fn (Line $line): array => [
            'entry_id'         => $entryId,
            'account_id'       => $line->accountId,
            'side'             => $line->side->value,
            'amount'           => $line->amount,
            'line_description' => $line->description,
            'position'         => $line->position,
        ], $lines));
    }

    private function entries(): BaseBuilder
    {
        return $this->db->table($this->tables->name('entries'));
    }

    private function lines(): BaseBuilder
    {
        return $this->db->table($this->tables->name('entry_lines'));
    }
}
