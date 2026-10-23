<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Domain\Chart;

use CognitioTechny\Contabil\Domain\Exception\ConflictException;
use CognitioTechny\Contabil\Domain\Exception\NotFoundException;
use CognitioTechny\Contabil\Domain\Exception\ValidationException;

/**
 * The invariants of the chart of accounts. It reads the chart and answers with
 * the account(s) to store; it never writes, so the caller decides when and in
 * which transaction the changes are saved.
 *
 * - Codes are unique per entity and follow the configured mask for their level.
 * - Only leaf accounts accept entries; an account with children is a group.
 * - The nature derives from the type, flipped for contra accounts.
 * - A child has its parent's type.
 * - Moving never creates a cycle.
 * - An account with movement cannot be deleted, change type or nature, or
 *   become a group; it can only be deactivated.
 */
final class ChartPolicy
{
    private const UPDATABLE = ['code', 'name', 'type', 'contra', 'accepts_entries', 'is_cash_account', 'reference_code'];

    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly AccountMovementInterface $movement,
        private readonly CodeMask $mask,
    ) {
    }

    /**
     * Builds a new, unsaved account. When the parent is a leaf it must be
     * turned into a group by the caller ({@see Account::asGroup()}).
     *
     * @throws ConflictException   The code is taken, or the parent has movement.
     * @throws NotFoundException   The parent does not exist in the entity.
     * @throws ValidationException Any other invariant is violated.
     */
    public function newAccount(
        int $entityId,
        string $code,
        string $name,
        ?AccountType $type = null,
        ?int $parentId = null,
        bool $contra = false,
        ?bool $acceptsEntries = null,
        bool $isCashAccount = false,
        ?string $referenceCode = null,
    ): Account {
        $code   = self::cleanCode($code);
        $name   = self::cleanName($name);
        $parent = null;

        if ($parentId !== null) {
            $parent = $this->requireAccount($entityId, $parentId);
            $this->assertCanHoldChildren($parent);
            $type ??= $parent->type;

            if ($type !== $parent->type) {
                throw new ValidationException(sprintf('The account type must be %s, the type of its parent "%s".', $parent->type->value, $parent->code));
            }
        }

        if ($type === null) {
            throw new ValidationException('The type is required for an account without a parent.');
        }

        $level = $parent === null ? 1 : $parent->level + 1;
        $this->mask->validate($code, $level);
        $this->assertCodeFree($entityId, $code, null);

        $account = new Account(
            null,
            $entityId,
            $code,
            $name,
            $type,
            $type->natureFor($contra),
            $level,
            $parentId,
            $acceptsEntries ?? true,
            $isCashAccount,
            true,
            self::cleanReference($referenceCode),
        );

        self::assertCashFlag($account);

        return $account;
    }

    /**
     * Applies a partial update. Recognised keys: code, name, type, contra,
     * accepts_entries, is_cash_account, reference_code.
     *
     * @param array<string, mixed> $changes
     */
    public function update(Account $current, array $changes): Account
    {
        $unknown = array_diff(array_keys($changes), self::UPDATABLE);

        if ($unknown !== []) {
            throw new ValidationException('Unknown account field(s): ' . implode(', ', $unknown) . '.');
        }

        $account = $current;

        if (array_key_exists('name', $changes)) {
            $account = $account->withName(self::cleanName((string) $changes['name']));
        }

        if (array_key_exists('code', $changes) && self::cleanCode((string) $changes['code']) !== $account->code) {
            $code = self::cleanCode((string) $changes['code']);
            $this->mask->validate($code, $account->level);
            $this->assertCodeFree($account->entityId, $code, $account->id);
            $account = $account->withCode($code);
        }

        if (array_key_exists('reference_code', $changes)) {
            $account = $account->withReferenceCode(self::cleanReference($changes['reference_code'] === null ? null : (string) $changes['reference_code']));
        }

        $account = $this->applyClassification($account, $changes);
        $account = $this->applyLeafOrGroup($account, $changes);

        if (array_key_exists('is_cash_account', $changes)) {
            $account = $account->withCashFlag((bool) $changes['is_cash_account']);
        }

        self::assertCashFlag($account);

        return $account;
    }

    /**
     * Plans a move under another parent (or to the root).
     *
     * @return array{moved: list<Account>, parent: Account|null} `moved` holds the account and every
     *                                                           descendant with their new level (and
     *                                                           the new code); `parent` is the new
     *                                                           parent when it must be turned into a
     *                                                           group, null otherwise. Both are empty
     *                                                           when nothing changes.
     */
    public function planMove(Account $account, ?int $newParentId, ?string $newCode = null): array
    {
        $code = $newCode === null ? $account->code : self::cleanCode($newCode);

        if ($newParentId === $account->parentId && $code === $account->code) {
            return ['moved' => [], 'parent' => null];
        }

        $descendants = $this->descendantsOf($account);
        $parent      = null;
        $convert     = null;

        if ($newParentId !== null) {
            $parent = $this->requireAccount($account->entityId, $newParentId);

            if ($parent->id === $account->id || isset($descendants[$parent->id])) {
                throw new ValidationException(sprintf('Moving "%s" under "%s" would create a cycle.', $account->code, $parent->code));
            }

            if ($parent->type !== $account->type) {
                throw new ValidationException(sprintf('"%s" is %s and cannot go under "%s", which is %s.', $account->code, $account->type->value, $parent->code, $parent->type->value));
            }

            $this->assertCanHoldChildren($parent);
            $convert = $parent->acceptsEntries ? $parent->asGroup() : null;
        }

        $level = $parent === null ? 1 : $parent->level + 1;
        $shift = $level - $account->level;

        $this->mask->validate($code, $level);

        if ($code !== $account->code) {
            $this->assertCodeFree($account->entityId, $code, $account->id);
        }

        $moved = [$account->withParent($newParentId, $level)->withCode($code)];

        foreach ($descendants as $descendant) {
            try {
                $this->mask->validate($descendant->code, $descendant->level + $shift);
            } catch (ValidationException $e) {
                throw new ValidationException(sprintf('Descendant "%s" would not fit the code mask after the move: %s', $descendant->code, $e->getMessage()), 0, $e);
            }

            $moved[] = $descendant->withLevel($descendant->level + $shift);
        }

        return ['moved' => $moved, 'parent' => $convert];
    }

    public function assertCanDeactivate(Account $account): void
    {
        foreach ($this->accounts->childrenOf($account->entityId, (int) $account->id) as $child) {
            if ($child->active) {
                throw new ConflictException(sprintf('Deactivate the children of "%s" first; "%s" is still active.', $account->code, $child->code));
            }
        }
    }

    public function assertCanReactivate(Account $account): void
    {
        if ($account->parentId === null) {
            return;
        }

        $parent = $this->accounts->find($account->entityId, $account->parentId);

        if ($parent !== null && ! $parent->active) {
            throw new ConflictException(sprintf('Reactivate the parent "%s" first.', $parent->code));
        }
    }

    public function assertCanDelete(Account $account): void
    {
        if ($this->accounts->childrenOf($account->entityId, (int) $account->id) !== []) {
            throw new ConflictException(sprintf('"%s" has children and cannot be deleted.', $account->code));
        }

        if ($this->movement->hasMovement((int) $account->id)) {
            throw new ConflictException(sprintf('"%s" has movement and cannot be deleted; deactivate it instead.', $account->code));
        }
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function applyClassification(Account $account, array $changes): Account
    {
        $type   = array_key_exists('type', $changes) ? AccountType::fromInput((string) $changes['type']) : $account->type;
        $contra = array_key_exists('contra', $changes) ? (bool) $changes['contra'] : $account->isContra();

        if ($type === $account->type && $contra === $account->isContra()) {
            return $account;
        }

        if ($this->movement->hasMovement((int) $account->id)) {
            throw new ConflictException(sprintf('"%s" has movement; its type and nature can no longer change.', $account->code));
        }

        if ($type !== $account->type) {
            if ($account->parentId !== null) {
                throw new ValidationException(sprintf('"%s" takes its type from its parent; move it instead of changing the type.', $account->code));
            }

            if ($this->accounts->childrenOf($account->entityId, (int) $account->id) !== []) {
                throw new ValidationException(sprintf('"%s" has children that inherit its type; it cannot change.', $account->code));
            }
        }

        return $account->withClassification($type, $contra);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function applyLeafOrGroup(Account $account, array $changes): Account
    {
        if (! array_key_exists('accepts_entries', $changes) || (bool) $changes['accepts_entries'] === $account->acceptsEntries) {
            return $account;
        }

        if ((bool) $changes['accepts_entries']) {
            if ($this->accounts->childrenOf($account->entityId, (int) $account->id) !== []) {
                throw new ValidationException(sprintf('"%s" has children, so it is a group and cannot accept entries.', $account->code));
            }

            return $account->asLeaf();
        }

        if ($this->movement->hasMovement((int) $account->id)) {
            throw new ConflictException(sprintf('"%s" has movement and cannot become a group.', $account->code));
        }

        return $account->asGroup();
    }

    /**
     * A leaf becomes a group when it gets a child, but only without movement.
     */
    private function assertCanHoldChildren(Account $parent): void
    {
        if (! $parent->active) {
            throw new ValidationException(sprintf('The parent "%s" is inactive.', $parent->code));
        }

        if ($parent->acceptsEntries && $this->movement->hasMovement((int) $parent->id)) {
            throw new ConflictException(sprintf('"%s" has movement, so it cannot become a group.', $parent->code));
        }
    }

    private function requireAccount(int $entityId, int $id): Account
    {
        return $this->accounts->find($entityId, $id) ?? throw new NotFoundException(sprintf('Account %d does not exist in this entity.', $id));
    }

    private function assertCodeFree(int $entityId, string $code, ?int $exceptId): void
    {
        $existing = $this->accounts->findByCode($entityId, $code);

        if ($existing !== null && $existing->id !== $exceptId) {
            throw new ConflictException(sprintf('The code "%s" is already in use in this entity.', $code));
        }
    }

    /**
     * @return array<int, Account> Every account below the given one, by id.
     */
    private function descendantsOf(Account $account): array
    {
        $byParent = [];

        foreach ($this->accounts->all($account->entityId) as $candidate) {
            $byParent[$candidate->parentId ?? 0][] = $candidate;
        }

        $found = [];
        $queue = [(int) $account->id];

        while ($queue !== []) {
            foreach ($byParent[array_shift($queue)] ?? [] as $child) {
                $found[(int) $child->id] = $child;
                $queue[]                 = (int) $child->id;
            }
        }

        return $found;
    }

    private static function assertCashFlag(Account $account): void
    {
        if ($account->isCashAccount && (! $account->acceptsEntries || $account->type !== AccountType::Asset)) {
            throw new ValidationException(sprintf('Only an asset account that accepts entries can be a cash account ("%s").', $account->code));
        }
    }

    private static function cleanCode(string $code): string
    {
        $code = trim($code);

        if ($code === '' || strlen($code) > 50 || preg_match('/[\x00-\x20\x7f]/', $code) === 1) {
            throw new ValidationException('The code is required, has at most 50 characters and no spaces.');
        }

        return $code;
    }

    private static function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > 150) {
            throw new ValidationException('The name is required and has at most 150 characters.');
        }

        return $name;
    }

    private static function cleanReference(?string $reference): ?string
    {
        $reference = $reference === null ? null : trim($reference);

        if ($reference === null || $reference === '') {
            return null;
        }

        if (strlen($reference) > 50) {
            throw new ValidationException('The reference code has at most 50 characters.');
        }

        return $reference;
    }
}
