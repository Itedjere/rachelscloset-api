<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\OrderPaid;
use App\Services\Payments\ConfirmPayment;
use App\Services\Payments\GatewayVerification;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The money spine.
 *
 * These are the tests that matter most in this section: a payment can be
 * confirmed by the webhook and by the payer returning, in either order and
 * sometimes at once, and applying it twice must be impossible.
 */
class ConfirmPaymentTest extends TestCase
{
    use RefreshDatabase;

    /** Swap the gateway for one that answers however the test needs. */
    private function gatewaySays(bool $successful, string $amount): void
    {
        $gateway = new class($successful, $amount) implements PaymentGateway
        {
            public function __construct(private bool $ok, private string $amount) {}

            public function name(): string
            {
                return 'flutterwave';
            }

            public function initialise(Payment $payment, string $returnUrl, string $description): string
            {
                return 'https://example.test/pay';
            }

            public function verify(string $reference): GatewayVerification
            {
                return new GatewayVerification(successful: $this->ok, amount: $this->amount);
            }

            public function refund(Payment $payment, string $amount): string
            {
                return 'refund-1';
            }

            public function referenceFromWebhook(string $payload, array $headers): ?string
            {
                return null;
            }
        };

        $this->instance(PaymentGatewayManager::class, new class($gateway) extends PaymentGatewayManager
        {
            public function __construct(private PaymentGateway $stub) {}

            public function active(): PaymentGateway
            {
                return $this->stub;
            }

            public function for(string $provider): PaymentGateway
            {
                return $this->stub;
            }

            public function configured(): bool
            {
                return true;
            }

            public function sandboxEnabled(): bool
            {
                return true;
            }
        });
    }

    private function pendingPayment(array $orderState = []): Payment
    {
        $order = Order::factory()->create($orderState + ['amount' => '25000.00']);

        return Payment::factory()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '25000.00',
        ]);
    }

    public function test_a_verified_payment_moves_the_order_into_progress(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '25000.00');

        $payment = $this->pendingPayment();

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
        $this->assertSame(Order::IN_PROGRESS, $payment->order->fresh()->status);

        Notification::assertSentTo($payment->order->tailor, OrderPaid::class);
    }

    /**
     * The test the unique index exists for.
     *
     * A webhook and the payer returning race each other constantly. Applying
     * twice would tell the tailor twice and, on an escrow order, could mint a
     * second payout.
     */
    public function test_confirming_twice_changes_nothing_and_tells_nobody_twice(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '25000.00');

        $payment = $this->pendingPayment(['escrow' => true]);
        $reference = $payment->provider_reference;

        app(ConfirmPayment::class)->handle($reference);
        app(ConfirmPayment::class)->handle($reference);
        app(ConfirmPayment::class)->handle($reference);

        $this->assertSame(1, Payment::where('provider_reference', $reference)->count());
        $this->assertSame(1, Payout::where('order_id', $payment->order_id)->count());
        Notification::assertSentToTimes($payment->order->tailor, OrderPaid::class, 1);
    }

    public function test_an_underpayment_is_not_settlement(): void
    {
        Notification::fake();
        // A payment begun before the price went up.
        $this->gatewaySays(true, '24999.99');

        $payment = $this->pendingPayment();

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame(Order::PENDING_PAYMENT, $payment->order->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_an_overpayment_still_settles(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '30000.00');

        $payment = $this->pendingPayment();

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        // The money is real, so the order advances. The difference is owed
        // back and is logged for an admin.
        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
        $this->assertSame(Order::IN_PROGRESS, $payment->order->fresh()->status);
    }

    public function test_a_failed_verification_leaves_the_order_alone(): void
    {
        $this->gatewaySays(false, '0');

        $payment = $this->pendingPayment();

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $this->assertSame(Payment::FAILED, $payment->fresh()->status);
        $this->assertSame(Order::PENDING_PAYMENT, $payment->order->fresh()->status);
    }

    public function test_a_reference_we_never_issued_is_refused(): void
    {
        $this->gatewaySays(true, '25000.00');

        $this->assertNull(app(ConfirmPayment::class)->handle('someone-elses-reference'));
    }

    /**
     * A late webhook must not drag an order backwards. Bank transfer and USSD
     * settle slowly, so "late" here can mean hours.
     */
    public function test_a_late_confirmation_does_not_reopen_a_finished_order(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '25000.00');

        $payment = $this->pendingPayment();
        // forceFill, for the same reason the production code does:
        // `status` is not mass-assignable, so update() would silently
        // do nothing and this test would pass for the wrong reason.
        $payment->order->forceFill(['status' => Order::READY])->save();

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
        $this->assertSame(Order::READY, $payment->order->fresh()->status);
    }

    public function test_a_deposit_that_only_part_pays_does_not_start_the_work(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '4000.00');

        $order = Order::factory()->create(['amount' => '25000.00', 'deposit_amount' => '10000.00']);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '4000.00',
        ]);

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        // Banked, but the deposit is not covered, so work has not been bought.
        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
        $this->assertSame(Order::PENDING_PAYMENT, $order->fresh()->status);
    }

    public function test_paying_the_deposit_starts_the_work(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '10000.00');

        $order = Order::factory()->create(['amount' => '25000.00', 'deposit_amount' => '10000.00']);
        $payment = Payment::factory()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '10000.00',
        ]);

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $this->assertSame(Order::IN_PROGRESS, $order->fresh()->status);
    }

    /** No commission, ever. The subscription is the revenue. */
    public function test_an_escrow_payout_deducts_nothing(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '25000.00');

        $payment = $this->pendingPayment(['escrow' => true]);

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        $payout = Payout::where('order_id', $payment->order_id)->sole();

        $this->assertSame('25000.00', $payout->gross_amount);
        $this->assertSame('25000.00', $payout->net_amount);
        $this->assertSame(Payout::PENDING, $payout->status);
    }

    public function test_a_direct_order_records_no_payout(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '25000.00');

        $payment = $this->pendingPayment(['escrow' => false]);

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        // The money never touched the platform, so there is nothing to pay out.
        $this->assertSame(0, Payout::where('order_id', $payment->order_id)->count());
    }

    public function test_a_subscription_payment_touches_no_order(): void
    {
        Notification::fake();
        $this->gatewaySays(true, '2000.00');

        $payment = Payment::factory()->create([
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'order_id' => null,
            'payer_id' => User::factory()->tailor()->create()->id,
            'amount' => '2000.00',
        ]);

        app(ConfirmPayment::class)->handle($payment->provider_reference);

        // Section 14 mints the term; this just must not fall over.
        $this->assertSame(Payment::SUCCESSFUL, $payment->fresh()->status);
    }
}
