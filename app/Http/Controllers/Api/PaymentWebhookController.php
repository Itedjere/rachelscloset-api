<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Services\Payments\ConfirmPayment;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Where Flutterwave tells us a payment landed.
 *
 * Unauthenticated by necessity -- the provider has no session -- so the
 * signature is the only thing separating this from an endpoint that marks any
 * order paid on request. Nothing here trusts the body beyond the reference it
 * names; ConfirmPayment then asks the provider directly.
 *
 * Always answers 200. A provider that receives anything else retries, and
 * retrying will not fix a forged signature or a reference we never issued --
 * it just fills their queue and ours. What happened is recorded instead.
 */
class PaymentWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly ConfirmPayment $confirm,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = json_decode($raw, true) ?: [];

        $reference = $this->gateways->active()->referenceFromWebhook($raw, $request->headers->all());

        if (! $reference) {
            /*
             * Either the signature did not match or it was not a successful
             * charge. Both are logged, and a bad signature especially:
             * somebody probing this endpoint is worth being able to see.
             */
            $this->record($payload, null, false, WebhookEvent::BAD_SIGNATURE);

            return response()->json(['status' => 'ignored']);
        }

        $existing = Payment::query()->where('provider_reference', $reference)->first();

        if (! $existing) {
            $this->record($payload, $reference, true, WebhookEvent::UNKNOWN_REFERENCE);

            return response()->json(['status' => 'ignored']);
        }

        // Already settled, by the payer returning to the site or by an earlier
        // delivery of this same webhook.
        if ($existing->isSuccessful()) {
            $this->record($payload, $reference, true, WebhookEvent::IGNORED_DUPLICATE);

            return response()->json(['status' => 'ok']);
        }

        $this->confirm->handle($reference);
        $this->record($payload, $reference, true, WebhookEvent::PROCESSED);

        return response()->json(['status' => 'ok']);
    }

    private function record(array $payload, ?string $reference, bool $valid, string $outcome): void
    {
        WebhookEvent::create([
            'provider' => 'flutterwave',
            'event' => $payload['event'] ?? null,
            'provider_reference' => $reference,
            'signature_valid' => $valid,
            'payload' => $payload,
            'outcome' => $outcome,
        ]);
    }
}
