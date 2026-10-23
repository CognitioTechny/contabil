<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Infrastructure\CodeIgniter\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Application\Entry\EntryResult;
use CognitioTechny\Contabil\Application\Support\DateInput;
use CognitioTechny\Contabil\Domain\Entry\EntryFilter;
use CognitioTechny\Contabil\Domain\Entry\EntryStatus;
use CognitioTechny\Contabil\Domain\Entry\JournalEntry;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * Journal entry API. Reading needs `contabil.entry.view`, drafts need
 * `contabil.entry.create`, posting (also settling and batches) needs
 * `contabil.entry.post`, reversing needs `contabil.entry.reverse`.
 */
class EntriesController extends ApiController
{
    private const VIEW    = 'contabil.entry.view';
    private const CREATE  = 'contabil.entry.create';
    private const POST    = 'contabil.entry.post';
    private const REVERSE = 'contabil.entry.reverse';

    public function index(): ResponseInterface
    {
        return $this->handle(self::VIEW, function (int $entity): array {
            $status = $this->request->getGet('status');
            $before = $this->request->getGet('before_id');
            $limit  = $this->request->getGet('limit');
            $filter = new EntryFilter(
                $status === null || $status === '' ? null : (EntryStatus::tryFrom(strtoupper((string) $status)) ?? throw new ValidationException('The status must be DRAFT, POSTED or REVERSED.')),
                DateInput::parseOptional($this->request->getGet('from'), 'from'),
                DateInput::parseOptional($this->request->getGet('to'), 'to'),
                $this->query('source_type'),
                $this->query('source_id'),
                $this->request->getGet('account_id') === null ? null : (int) $this->request->getGet('account_id'),
            );

            return array_map(
                static fn (JournalEntry $entry): array => $entry->toArray(),
                $this->useCases()->entries()->list($entity, $filter, $before === null ? null : (int) $before, $limit === null ? 50 : (int) $limit),
            );
        });
    }

    public function show(string $id): ResponseInterface
    {
        return $this->handle(self::VIEW, function (int $entity) use ($id): array {
            $entries = $this->useCases()->entries();

            return $entries->get($entity, (int) $id)->toArray() + [
                'settlements' => array_map(static fn ($s): array => $s->toArray(), $entries->settlementsOf($entity, (int) $id)),
            ];
        });
    }

    /**
     * Creates a draft, or with `"post": true` posts it right away. Answers 201,
     * or 200 with the stored entry when the idempotency key was already used.
     */
    public function create(): ResponseInterface
    {
        return $this->handle(self::CREATE, function (int $entity): ResponseInterface {
            $body = $this->jsonBody();
            $post = (bool) ($body['post'] ?? false);

            if ($post) {
                $this->also(self::POST, $entity);
            }

            $result = $this->useCases()->createEntry()->execute($entity, EntryData::fromArray($body), $post);

            return $this->json(['data' => $result->entry->toArray()], $result->created ? 201 : 200);
        });
    }

    public function update(string $id): ResponseInterface
    {
        return $this->handle(self::CREATE, fn (int $entity): array => $this->useCases()->manageDraft()->update($entity, (int) $id, EntryData::fromArray($this->jsonBody()))->toArray());
    }

    public function delete(string $id): ResponseInterface
    {
        return $this->handle(self::CREATE, function (int $entity) use ($id): ResponseInterface {
            $this->useCases()->manageDraft()->delete($entity, (int) $id);

            return $this->response->setStatusCode(204);
        });
    }

    public function post(string $id): ResponseInterface
    {
        return $this->handle(self::POST, fn (int $entity): array => $this->useCases()->postEntry()->execute($entity, (int) $id)->toArray());
    }

    public function reverse(string $id): ResponseInterface
    {
        return $this->handle(self::REVERSE, function (int $entity) use ($id): array {
            $body   = $this->jsonBody();
            $result = $this->useCases()->reverseEntry()->execute(
                $entity,
                (int) $id,
                (string) ($body['reason'] ?? ''),
                isset($body['date']) ? (string) $body['date'] : null,
            );

            return ['original' => $result->original->toArray(), 'reversal' => $result->reversal->toArray()];
        }, 201);
    }

    /**
     * Records that entry `{id}` (a payment or collection) settles another entry.
     */
    public function settle(string $id): ResponseInterface
    {
        return $this->handle(self::POST, function (int $entity) use ($id): array {
            $body = $this->jsonBody();

            if (! isset($body['settled_entry_id'], $body['amount']) || ! is_int($body['amount'])) {
                throw new ValidationException('settled_entry_id and an integer amount in minor units are required.');
            }

            return $this->useCases()->settleEntry()->execute(
                $entity,
                (int) $id,
                (int) $body['settled_entry_id'],
                $body['amount'],
                isset($body['date']) ? (string) $body['date'] : null,
            )->toArray();
        }, 201);
    }

    /**
     * Creates and posts up to 500 entries atomically.
     */
    public function batch(): ResponseInterface
    {
        return $this->handle(self::POST, function (int $entity): ResponseInterface {
            $body    = $this->jsonBody();
            $entries = $body['entries'] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                throw new ValidationException('entries must be a list.');
            }

            $results = $this->useCases()->postBatch()->execute($entity, array_map(
                static fn (mixed $item): EntryData => EntryData::fromArray(is_array($item) ? $item : throw new ValidationException('Each entry must be an object.')),
                $entries,
            ));

            $created = array_filter($results, static fn (EntryResult $r): bool => $r->created);

            return $this->json(['data' => array_map(static fn (EntryResult $r): array => $r->entry->toArray() + ['created' => $r->created], $results)], $created === [] ? 200 : 201);
        });
    }

    private function query(string $name): ?string
    {
        $value = $this->request->getGet($name);

        return $value === null || $value === '' ? null : (string) $value;
    }
}
