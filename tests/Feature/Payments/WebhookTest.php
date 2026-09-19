<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The webhook endpoint.
 *
 * Unauthenticated by necessity, so the signature is the only thing between it
 * and an endpoint that marks any order paid on request. These tests are the
 * proof that it is.
 */
class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const HASH = 'a-long-unguessable-value';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.flutterwave.webhook_hash' => self::HASH,
            'services.flutterwave.secret' => 'test-secret',
            // The real gateway, so the signature check under test is the real
            // one. Only the HTTP calls it makes are faked.
            'services.flutterwave.sandbox' => false,
        ]);

        Notification::fake();
    }

    private function payment(array $overrides = []): Payment
    {
        $order = Order::factory()->create(['amount' => '25000.00'] + $overrides);

        return Payment::factory()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '25000.00',
        ]);
    }

    /** What Flutterwave says when a charge succeeds. */
    private function body(string $reference, string $status = 'successful'): array
    {
        return [
            'event' => 'charge.completed',
            'data' => ['tx_ref' => $reference, 'status' => $status, 'amount' => 25000],
        ];
    }

    private function verifySays(string $reference, string $amount = '25000'): void
    {
        Http::fake([
            '*/transactions/verify_by_reference*' => Http::response([
                'status' => 'success',
                'data' => ['status' => 'successful', 'amount' => $amount, 'currency' => 'NGN'],
            ]),
        ]);
    }

    public function test_a_signed_webhook_settles_the_order(): void
    {
        $payment = $this->payment();
        $this->verifySays($payment->provider_reference);

        $this->postJson('/api/webhooks/flutterwave', $this->body($payment->provider_reference), [
            'verif-hash' => self::HASH,
        ])->assertOk();

        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
        $this->assertSame(Order::IN_PROGRESS, $payment->order->fresh()->status);
        $this->assertDatabaseHas('webhook_events', ['outcome' => WebhookEvent::PROCESSED]);
    }

    /** The one that matters. Without it this endpoint is a free order. */
    public function test_a_wrong_signature_changes_nothing(): void
    {
        $payment = $this->payment();

        $this->postJson('/api/webhooks/flutterwave', $this->body($payment->provider_reference), [
            'verif-hash' => 'not-the-hash',
        ])->assertOk();

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
        $this->assertSame(Order::PENDING_PAYMENT, $payment->order->fresh()->status);

        // Recorded, because somebody probing this is worth being able to see.
        $this->assertDatabaseHas('webhook_events', [
            'outcome' => WebhookEvent::BAD_SIGNATURE,
            'signature_valid' => false,
        ]);
    }

    public function test_a_missing_signature_changes_nothing(): void
    {
        $payment = $this->payment();

        $this->postJson('/api/webhooks/flutterwave', $this->body($payment->provider_reference))->assertOk();

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
    }

    /**
     * An unset hash must fail closed.
     *
     * A server deployed before somebody pasted the value into .env would
     * otherwise accept every webhook, signed or not, and nothing would look
     * wrong until it was.
     */
    public function test_an_unconfigured_hash_refuses_everything(): void
    {
        config(['services.flutterwave.webhook_hash' => '']);

        $payment = $this->payment();

        $this->postJson('/api/webhooks/flutterwave', $this->body($payment->provider_reference), [
            'verif-hash' => '',
        ])->assertOk();

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
    }

    public function test_a_replayed_webhook_is_recorded_but_not_reapplied(): void
    {
        $payment = $this->payment(['escrow' => true]);
        $this->verifySays($payment->provider_reference);

        $body = $this->body($payment->provider_reference);
        $headers = ['verif-hash' => self::HASH];

        $this->postJson('/api/webhooks/flutterwave', $body, $headers)->assertOk();
        $this->postJson('/api/webhooks/flutterwave', $body, $headers)->assertOk();
        $this->postJson('/api/webhooks/flutterwave', $body, $headers)->assertOk();

        $this->assertSame(1, $payment->order->payments()->where('status', Payment::SUCCESSFUL)->count());
        $this->assertSame(1, Payout::where('order_id', $payment->order_id)->count());
        $this->assertSame(2, WebhookEvent::where('outcome', WebhookEvent::IGNORED_DUPLICATE)->count());
    }

    public function test_a_reference_we_never_issued_is_logged_and_ignored(): void
    {
        $this->postJson('/api/webhooks/flutterwave', $this->body('RC-NOT-OURS'), [
            'verif-hash' => self::HASH,
        ])->assertOk();

        $this->assertDatabaseHas('webhook_events', ['outcome' => WebhookEvent::UNKNOWN_REFERENCE]);
    }

    public function test_a_failed_charge_is_not_treated_as_payment(): void
    {
        $payment = $this->payment();

        $this->postJson('/api/webhooks/flutterwave', $this->body($payment->provider_reference, 'failed'), [
            'verif-hash' => self::HASH,
        ])->assertOk();

        $this->assertSame(Payment::PENDING, $payment->fresh()->status);
    }

    /**
     * Always 200.
     *
     * Anything else makes the provider retry, and retrying will not fix a
     * forged signature or an unknown reference -- it just fills their queue
     * and ours.
     */
    public function test_every_outcome_answers_two_hundred(): void
    {
        $payment = $this->payment();
        $this->verifySays($payment->provider_reference);

        $this->postJson('/api/webhooks/flutterwave', $this->body('nope'), ['verif-hash' => 'wrong'])->assertOk();
        $this->postJson('/api/webhooks/flutterwave', $this->body('nope'), ['verif-hash' => self::HASH])->assertOk();
        $this->postJson('/api/webhooks/flutterwave', [], ['verif-hash' => self::HASH])->assertOk();
    }
}
