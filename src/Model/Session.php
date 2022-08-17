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
        private readonly Account $selectedAccount,
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

    public function getSelectedAccount(): Account
    {
        return $this->selectedAccount;
    }

    public function getSourceAccount(): Account
    {
        return $this->sourceAccount;
    }

    public function isSourceAccountSelected(): bool
    {
        return $this->sourceAccount->equals($this->getSelectedAccount());
    }
}
