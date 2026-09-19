<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\TailorProfile;

/**
 * Sending money out, as opposed to taking it in.
 *
 * A separate interface from PaymentGateway on purpose. Taking money in and
 * paying it out fail in completely different ways -- one strands a customer
 * mid-checkout, the other strands a tailor who has already done the work --
 * and the second is the one that must never silently do nothing.
 */
interface TransferGateway
{
    /**
     * Checks the account is real and returns what the bank says it is called.
     *
     * The name matters as much as the verification: showing her the name the
     * bank returned, before any money moves, is what catches a transposed
     * digit while it is still free to fix.
     */
    public function resolveAccount(string $bankCode, string $accountNumber): ResolvedAccount;

    /** Sends one payout. Returns the provider reference for the transfer. */
    public function transfer(Payout $payout, TailorProfile $profile): string;

    /** The bank list, so she picks rather than types. */
    public function banks(): array;
}
