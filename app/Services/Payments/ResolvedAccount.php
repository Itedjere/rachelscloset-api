<?php

namespace App\Services\Payments;

/** What the bank says about an account number. */
class ResolvedAccount
{
    public function __construct(
        public readonly string $accountName,
        public readonly string $bankCode,
        public readonly string $accountNumber,
    ) {}

    /**
     * Flutterwave has no stored-recipient concept the way some providers do --
     * a transfer carries the account details every time. So this is our own
     * marker that these particular details were verified, and it is checked
     * before a transfer so an account cannot be silently swapped after the
     * fact.
     */
    public function recipientCode(): string
    {
        return 'FLW:'.$this->bankCode.':'.$this->accountNumber;
    }
}
