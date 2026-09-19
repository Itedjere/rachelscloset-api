<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Models\TailorProfile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FlutterwaveTransfers implements TransferGateway
{
    public function resolveAccount(string $bankCode, string $accountNumber): ResolvedAccount
    {
        $response = $this->client()->post('/accounts/resolve', [
            'account_number' => $accountNumber,
            'account_bank' => $bankCode,
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException(
                'That account could not be verified: '.($response->json('message') ?? 'unknown error')
            );
        }

        return new ResolvedAccount(
            // The name the BANK returned, not the one she typed. Showing it
            // back is the whole point of resolving before paying.
            accountName: (string) $response->json('data.account_name'),
            bankCode: $bankCode,
            accountNumber: $accountNumber,
        );
    }

    public function transfer(Payout $payout, TailorProfile $profile): string
    {
        /*
         * The stored marker has to still match the stored details. If somebody
         * edited the account number without re-resolving, the marker goes
         * stale and this refuses rather than paying an unverified account.
         */
        $expected = 'FLW:'.$profile->bank_code.':'.$profile->bank_account_number;

        if ($profile->transfer_recipient !== $expected) {
            throw new RuntimeException('Those bank details have not been verified.');
        }

        /*
         * Idempotent by construction: the reference is derived from the payout
         * id alone, with no timestamp. Flutterwave rejects a duplicate
         * reference, which means a retry after a timeout cannot pay twice --
         * the failure we least want is the one where we are unsure whether the
         * money left.
         */
        $reference = 'RCPAYOUT-'.$payout->id;

        $response = $this->client()->post('/transfers', [
            'account_bank' => $profile->bank_code,
            'account_number' => $profile->bank_account_number,
            // Naira. No kobo conversion anywhere in this codebase.
            'amount' => (float) $payout->net_amount,
            'currency' => 'NGN',
            'reference' => $reference,
            'narration' => "Rachel's Closet order ".$payout->order->reference,
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException(
                'Flutterwave refused the transfer: '.($response->json('message') ?? 'unknown error')
            );
        }

        return (string) ($response->json('data.reference') ?? $reference);
    }

    public function banks(): array
    {
        // Cached for a day: the list changes rarely and the account form should
        // not wait on a network call every time it opens.
        return Cache::remember('flutterwave.banks', now()->addDay(), function () {
            $response = $this->client()->get('/banks/NG');

            if (! $response->successful() || $response->json('status') !== 'success') {
                return [];
            }

            return collect($response->json('data'))
                ->map(fn (array $bank) => ['name' => $bank['name'], 'code' => $bank['code']])
                ->sortBy('name')
                ->values()
                ->all();
        });
    }

    private function client()
    {
        return Http::withToken((string) config('services.flutterwave.secret'))
            ->acceptJson()
            ->baseUrl((string) config('services.flutterwave.base_url'))
            ->timeout(30);
    }
}
