<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Entry;

use CognitioTechny\Contabil\Domain\Entry\EntryRepositoryInterface;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\PostingPolicy;
use CognitioTechny\Contabil\Domain\Event\EntryPosted;
use CognitioTechny\Contabil\Domain\Exception\ConflictException;

/**
 * Stores one entry, optionally posting it, honouring the idempotency key.
 * Shared by {@see CreateEntry} and {@see PostBatch}; it runs inside the
 * caller's transaction and returns the events to publish after the commit.
 */
final class EntryRecorder
{
    public function __construct(
        private readonly EntryRepositoryInterface $entries,
        private readonly PostingPolicy $policy,
        private readonly EntryPoster $poster,
    ) {
    }

    /**
     * @return array{0: EntryResult, 1: list<object>}
     */
    public function record(int $entityId, EntryData $data, ?string $userId, bool $post): array
    {
        $draft = $data->toDraft($entityId, $userId);

        if ($draft->idempotencyKey !== null) {
            $existing = $this->entries->findByIdempotencyKey($entityId, $draft->idempotencyKey);

            if ($existing !== null) {
                if (! $existing->sameContentAs($draft)) {
                    throw new ConflictException(sprintf('The idempotency key "%s" was already used with different content.', $draft->idempotencyKey));
                }

                if ($post && $existing->status === EntryStatus::Draft) {
                    $posted = $this->poster->post($existing);

                    return [new EntryResult($posted, false), [new EntryPosted($posted)]];
                }

                return [new EntryResult($existing, false), []];
            }
        }

        $this->policy->assertAccounts($draft);

        $stored = $this->entries->insert($draft);

        if (! $post) {
            return [new EntryResult($stored, true), []];
        }

        $posted = $this->poster->post($stored);

        return [new EntryResult($posted, true), [new EntryPosted($posted)]];
    }
}
