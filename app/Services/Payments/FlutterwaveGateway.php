<?php

namespace App\Services\Payments;

use App\Models\Payment;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Flutterwave, v3.
 *
 * v3 is deliberate, not legacy: as of September 2026 it is the stable
 * production API with no deprecation announced, while v4 is public beta. v4
 * also changes the auth model from a bearer secret key to OAuth client
 * credentials, so moving is a real piece of work rather than a base-URL swap.
 * When that happens it is this one class that changes.
 *
 * Naira throughout. Flutterwave takes naira directly, so there is no kobo
 * conversion anywhere in this codebase.
 */
class FlutterwaveGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'flutterwave';
    }

    public function initialise(Payment $payment, string $returnUrl, string $description): string
    {
        $payer = $payment->payer;

        $response = $this->client()->post('/payments', [
            'tx_ref' => $payment->provider_reference,
            // The PAYMENT amount, not the order total: an order with a deposit
            // charges the deposit now and the rest on collection.
            'amount' => (float) $payment->amount,
            'currency' => 'NGN',
            'redirect_url' => $returnUrl,
            'payment_options' => 'card,banktransfer,ussd,account',
            'customer' => [
                'email' => $this->emailFor($payer),
                'name' => $payer->name,
                'phonenumber' => $payer->phone,
            ],
            'customizations' => [
                'title' => 'Rachels Closet',
                'description' => $description,
            ],
            'meta' => [
                'payment_id' => $payment->id,
                'purpose' => $payment->purpose,
                'order_id' => $payment->order_id,
            ],
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException(
                'Flutterwave could not start this payment: '.($response->json('message') ?? 'unknown error')
            );
        }

        return $response->json('data.link');
    }

    /**
     * An address for somebody who has none.
     *
     * Flutterwave requires a customer email and most people here do not have
     * one -- the phone is the username, and nothing in this project sends
     * email. A deterministic address on a subdomain that resolves nowhere
     * satisfies the field without pretending mail will arrive. The platform
     * tells her herself, in the app and on her phone.
     */
    private function emailFor($payer): string
    {
        return filled($payer->email)
            ? $payer->email
            : $payer->phone.'@no-mail.rachelscloset.com.ng';
    }

    public function verify(string $reference): GatewayVerification
    {
        /*
         * Verified by OUR reference, not their transaction id. A payer
         * returning to the site with a tampered query string cannot point the
         * verification at somebody else's successful transaction.
         */
        $response = $this->client()->get('/transactions/verify_by_reference', [
            'tx_ref' => $reference,
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            return GatewayVerification::failed($response->json() ?? []);
        }

        $data = $response->json('data') ?? [];

        return new GatewayVerification(
            successful: ($data['status'] ?? null) === 'successful',
            // A string, so the comparison against what is owed never goes
            // through a float.
            amount: (string) ($data['amount'] ?? '0'),
            currency: $data['currency'] ?? 'NGN',
            raw: $data,
        );
    }

    public function refund(Payment $payment, string $amount): string
    {
        // Flutterwave addresses a refund by its own transaction id rather than
        // our reference, so the charge has to be looked up first.
        $verify = $this->client()->get('/transactions/verify_by_reference', [
            'tx_ref' => $payment->provider_reference,
        ]);

        $transactionId = $verify->json('data.id');

        if (! $transactionId) {
            throw new RuntimeException('Flutterwave has no record of this payment to refund.');
        }

        $response = $this->client()->post('/transactions/'.$transactionId.'/refund', [
            'amount' => (float) $amount,
        ]);

        if (! $response->successful() || $response->json('status') !== 'success') {
            throw new RuntimeException(
                'Flutterwave refused this refund: '.($response->json('message') ?? 'unknown error')
            );
        }

        return (string) ($response->json('data.id') ?? $transactionId);
    }

    public function referenceFromWebhook(string $payload, array $headers): ?string
    {
        $hash = $this->header($headers, 'verif-hash');
        $expected = (string) config('services.flutterwave.webhook_hash');

        /*
         * Flutterwave sends the secret hash you configured in their dashboard
         * verbatim, rather than an HMAC of the body. So this is a constant-time
         * comparison of two strings, and the hash must be long and random --
         * it is the only thing standing between the endpoint and anybody who
         * can guess it.
         */
        if ($hash === null || $expected === '' || ! hash_equals($expected, $hash)) {
            return null;
        }

        $body = json_decode($payload, true);
        $data = $body['data'] ?? [];

        if (($data['status'] ?? null) !== 'successful') {
            return null;
        }

        return $data['tx_ref'] ?? null;
    }

    private function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower($key) === $name) {
                return is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    private function client()
    {
        return Http::withToken((string) config('services.flutterwave.secret'))
            ->acceptJson()
            ->baseUrl((string) config('services.flutterwave.base_url'))
            ->timeout(30);
    }
}
