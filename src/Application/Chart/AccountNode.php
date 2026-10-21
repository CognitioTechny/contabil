<?php

declare(strict_types=1);

namespace CognitioTechny\Contabil\Application\Chart;

use CognitioTechny\Contabil\Domain\Chart\Account;

/**
 * An account with its children, as returned by {@see GetAccountTree}.
 */
final class AccountNode
{
    /**
     * @param list<AccountNode> $children Ordered by code.
     */
    public function __construct(
        public readonly Account $account,
        public readonly array $children = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->account->toArray() + [
            'children' => array_map(static fn (self $child): array => $child->toArray(), $this->children),
        ];
    }
}
