<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\PostingRule;

use CognitioTechny\Contabil\Application\Entry\CreateEntry;
use CognitioTechny\Contabil\Application\Entry\EntryData;
use CognitioTechny\Contabil\Application\Entry\EntryResult;
use CognitioTechny\Contabil\Application\Repository\EntityGuard;
use CognitioTechny\Contabil\Domain\Chart\AccountRepositoryInterface;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;
use CognitioTechny\Contabil\Domain\PostingRule\PostingRule;
use CognitioTechny\Contabil\Domain\PostingRule\PostingRuleRepositoryInterface;

/**
 * Optional helper for hosts: register which accounts an event of theirs
 * debits and credits, then post an event without naming accounts.
 *
 * When no active rule matches an event, {@see post()} returns null and writes
 * nothing; the host keeps that event in its own pending queue.
 */
final class PostingRules
{
    public function __construct(
        private readonly EntityGuard $entities,
        private readonly PostingRuleRepositoryInterface $rules,
        private readonly AccountRepositoryInterface $accounts,
        private readonly CreateEntry $createEntry,
    ) {
    }

    /**
     * @param array<string, scalar> $condition Attribute values that must all match.
     *
     * @throws ValidationException An account is not an active leaf of the entity.
     */
    public function register(int $entityId, string $event, int $debitAccountId, int $creditAccountId, array $condition = []): PostingRule
    {
        $this->entities->assertExists($entityId);

        $event = trim($event);

        if ($event === '' || strlen($event) > 100) {
            throw new ValidationException('The event name has 1 to 100 characters.');
        }

        if ($debitAccountId === $creditAccountId) {
            throw new ValidationException('The debit and credit accounts must differ.');
        }

        foreach ($condition as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                throw new ValidationException('The condition maps attribute names to scalar values.');
            }
        }

        foreach ([$debitAccountId, $creditAccountId] as $id) {
            $account = $this->accounts->find($entityId, $id);

            if ($account === null || ! $account->acceptsEntries || ! $account->active) {
                throw new ValidationException(sprintf('Account %d must be an active account that accepts entries.', $id));
            }
        }

        return $this->rules->insert(new PostingRule(null, $entityId, $event, $condition, $debitAccountId, $creditAccountId));
    }

    /**
     * @return list<PostingRule>
     */
    public function list(int $entityId): array
    {
        $this->entities->assertExists($entityId);

        return $this->rules->all($entityId);
    }

    /**
     * @throws NotFoundException
     */
    public function setActive(int $entityId, int $ruleId, bool $active): PostingRule
    {
        $rule = $this->rules->find($entityId, $ruleId) ?? throw new NotFoundException(sprintf('Posting rule %d does not exist in this entity.', $ruleId));
        $rule = $rule->withActive($active);

        $this->rules->save($rule);

        return $rule;
    }

    /**
     * Posts the two-line entry that the first matching rule describes.
     *
     * @param string                $event      Host event name.
     * @param int                   $amount     Minor units.
     * @param string                $date       Accrual date, `YYYY-MM-DD`.
     * @param array<string, mixed>  $attributes Values the rule conditions are tested against.
     * @param array<string, string> $details    Optional `cash_date`, `source_type`, `source_id` and `idempotency_key`.
     *
     * @return EntryResult|null Null when no rule matches.
     */
    public function post(int $entityId, string $event, int $amount, string $date, string $description, array $attributes = [], array $details = []): ?EntryResult
    {
        $this->entities->assertExists($entityId);

        foreach ($this->rules->activeForEvent($entityId, $event) as $rule) {
            if (! $rule->matches($attributes)) {
                continue;
            }

            return $this->createEntry->execute($entityId, new EntryData(
                $date,
                $description,
                [
                    ['account_id' => $rule->debitAccountId, 'side' => 'D', 'amount' => $amount],
                    ['account_id' => $rule->creditAccountId, 'side' => 'C', 'amount' => $amount],
                ],
                $details['cash_date'] ?? null,
                $details['source_type'] ?? null,
                $details['source_id'] ?? null,
                $details['idempotency_key'] ?? null,
            ), true);
        }

        return null;
    }
}
