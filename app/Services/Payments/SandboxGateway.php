<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * A provider that always says yes, for local work and for the test suite.
 *
 * Selected by config rather than by environment, so it can be switched on
 * deliberately. It exists so the order flow can be exercised without a network
 * round trip -- NOT as a substitute for testing against the real sandbox.
 * Everything here agrees with itself by construction, which is exactly the
 * class of bug a real integration surfaces and this one cannot.
 */
class SandboxGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'flutterwave';
    }

    public function initialise(Payment $payment, string $returnUrl, string $description): string
    {
        // Straight back to the return URL, as if the payer had paid instantly.
        return $returnUrl.(str_contains($returnUrl, '?') ? '&' : '?')
            .'tx_ref='.urlencode($payment->provider_reference).'&status=successful';
    }

    public function verify(string $reference): GatewayVerification
    {
        $payment = Payment::query()->where('provider_reference', $reference)->first();

        if (! $payment) {
            return GatewayVerification::failed(['sandbox' => 'unknown reference']);
        }

        return new GatewayVerification(
            successful: true,
            amount: (string) $payment->amount,
            raw: ['sandbox' => true],
        );
    }

    public function refund(Payment $payment, string $amount): string
    {
        return 'sandbox-refund-'.$payment->id;
    }

    /**
     * Accepts anything with a reference.
     *
     * No signature to check, which is precisely why this must never be the
     * gateway in production: the webhook endpoint would take instructions from
     * anybody. `PaymentGatewayManager` refuses to hand this back outside local.
     */
    public function referenceFromWebhook(string $payload, array $headers): ?string
    {
        $body = json_decode($payload, true);

        return $body['data']['tx_ref'] ?? null;
    }
}
