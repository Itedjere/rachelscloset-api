<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\TailorProfile;

/**
 * Transfers that always work, for local use and the test suite.
 *
 * Gated to local by TransferManager, for the same reason the sandbox payment
 * gateway is: a server that thinks it has sent money it has not sent is worse
 * than one that refuses to send any.
 */
class SandboxTransfers implements TransferGateway
{
    public function resolveAccount(string $bankCode, string $accountNumber): ResolvedAccount
    {
        return new ResolvedAccount('SANDBOX ACCOUNT', $bankCode, $accountNumber);
    }

    public function transfer(Payout $payout, TailorProfile $profile): string
    {
        return 'sandbox-transfer-'.$payout->id;
    }

    public function banks(): array
    {
        // Enough real Nigerian banks to exercise a picker.
        return [
            ['name' => 'Access Bank', 'code' => '044'],
            ['name' => 'First Bank of Nigeria', 'code' => '011'],
            ['name' => 'Guaranty Trust Bank', 'code' => '058'],
            ['name' => 'Kuda Bank', 'code' => '50211'],
            ['name' => 'Moniepoint MFB', 'code' => '50515'],
            ['name' => 'Opay', 'code' => '999992'],
            ['name' => 'Palmpay', 'code' => '999991'],
            ['name' => 'United Bank for Africa', 'code' => '033'],
            ['name' => 'Zenith Bank', 'code' => '057'],
        ];
    }
}
