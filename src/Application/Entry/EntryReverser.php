<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Event\EntryPosted;
use CognitioTechny\Contabil\Domain\Event\EntryReversed;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use DateTimeImmutable;

/**
 * Cancels a posted entry by posting its mirror image. Runs inside the caller's
 * transaction; {@see ReverseEntry} and the fiscal year reopening both use it.
 */
final class EntryReverser
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly EntryPoster $poster,
    ) {
    }

    /**
     * @param DateTimeImmutable|null $date Accrual date of the reversal; defaults to the original's.
     *
     * @return array{0: ReversalResult, 1: list<object>}
     *
     * @throws ConflictException Already reversed, a reversal itself, still settled, or the period is closed.
     * @throws NotFoundException
     */
    public function reverse(int $entityId, int $entryId, string $reason, ?string $userId, ?DateTimeImmutable $date = null): array
    {
        // Serializes concurrent reversals of the same entry before its status is read.
        $this->entries->lockEntity($entityId);

        $original = $this->entries->find($entityId, $entryId)
            ?? throw new NotFoundException(sprintf('Entry %d does not exist in this entity.', $entryId));

        $draft = $original->reversal($date ?? $original->accrualDate, $reason, $userId);

        if ($this->entries->activeSettlementsOf((int) $original->id) !== []) {
            throw new ConflictException('The entry has settlements; reverse the settlement entries first.');
        }

        $posted = $this->poster->post($this->entries->insert($draft));

        $this->entries->markReversed($entityId, (int) $original->id);
        $original = $original->markReversed();

        return [new ReversalResult($original, $posted), [new EntryPosted($posted), new EntryReversed($original, $posted, $draft->reversalReason ?? $reason)]];
    }
}
