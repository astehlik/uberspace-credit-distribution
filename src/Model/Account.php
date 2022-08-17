<?php

namespace App\Model;

use Webmozart\Assert\Assert;

final class Account
{
    public function __construct(
        private readonly string $name,
        private readonly string $server,
        private readonly int $creditInCents,
        private readonly int $priceInCents
    ) {
        Assert::notEmpty($name);
        Assert::notEmpty($server);
    }

    public function equals(Account $otherAccount): bool
    {
        return $this->name === $otherAccount->name;
    }

    public function getCreditInCents(): int
    {
        return $this->creditInCents;
    }

    public function getMissingAmount(int $targetAmountInCents): int
    {
        if ($this->creditInCents >= $targetAmountInCents) {
            return 0;
        }
        return $targetAmountInCents - $this->creditInCents;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getPriceInCents(): int
    {
        return $this->priceInCents;
    }

    public function getServer(): string
    {
        return $this->server;
    }
}
