<?php

namespace App\Model;

use Webmozart\Assert\Assert;

final class Session
{
    /**
     * @param array<int, Account> $fillableAccounts
     */
    public function __construct(
        private readonly Account $sourceAccount,
        private readonly array $fillableAccounts
    ) {
        Assert::allIsInstanceOf($this->fillableAccounts, Account::class);
    }

    /**
     * @return Account[]
     */
    public function getFillableAccounts(): array
    {
        return $this->fillableAccounts;
    }

    public function getSourceAccount(): Account
    {
        return $this->sourceAccount;
    }
}
