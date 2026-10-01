<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\DirectPaymentRecorded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A direct order: the customer pays the tailor by hand, the tailor says so.
 *
 * The hole this closed: a direct order could only leave pending_payment
 * through the Pay button, which charged the customer into the PLATFORM's
 * Flutterwave account with no payout recorded. The tailor was never paid.
 */
class DirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private User $customer;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.flutterwave.sandbox' => true]);

        $this->tailor = User::factory()->tailor()->create();
        $this->customer = User::factory()->customer()->create();

        $this->order = Order::factory()->create([
            'tailor_id' => $this->tailor->id,
            'customer_id' => $this->customer->id,
            'amount' => '40000.00',
            'deposit_amount' => '20000.00',
            'escrow' => false,
        ]);
    }

    private function record(string $amount)
    {
        return $this->postJson("/api/orders/{$this->order->id}/direct-payments", ['amount' => $amount]);
    }

    /** The bug itself: Pay on a direct order took the money into the platform. */
    public function test_a_direct_order_cannot_be_paid_through_the_platform(): void
    {
        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$this->order->id}/pay")
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Pay your tailor directly'));

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_recording_the_deposit_starts_the_work_and_sends_her_a_receipt(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->record('20000')->assertCreated()->assertJsonPath('data.amount', '20000.00');

        $order = $this->order->fresh();
        $this->assertSame(Order::IN_PROGRESS, $order->status);
        $this->assertSame('20000.00', number_format((float) $order->paidTotal(), 2, '.', ''));

        $payment = Payment::query()->sole();
        $this->assertTrue($payment->isDirect());
        $this->assertSame($this->customer->id, $payment->payer_id);

        Notification::assertSentTo($this->customer, DirectPaymentRecorded::class);
    }

    /** Part of a deposit is money banked, not work bought. */
    public function test_less_than_the_deposit_keeps_it_waiting(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->record('5000')->assertCreated();

        $this->assertSame(Order::PENDING_PAYMENT, $this->order->fresh()->status);

        $this->record('15000')->assertCreated();

        $this->assertSame(Order::IN_PROGRESS, $this->order->fresh()->status);
    }

    /** Never more than is owed: "paid so far" above the price is always a typo. */
    public function test_it_cannot_go_past_what_is_owed(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->record('400000')->assertJsonValidationErrors('amount');
        $this->record('40000')->assertCreated();
        $this->record('1')->assertJsonValidationErrors('amount');

        $this->assertSame(1, Payment::query()->count());
    }

    public function test_only_the_tailor_on_a_direct_order_may_record_one(): void
    {
        Sanctum::actingAs($this->customer);
        $this->record('20000')->assertForbidden();

        Sanctum::actingAs(User::factory()->tailor()->create());
        $this->record('20000')->assertNotFound();

        Sanctum::actingAs($this->tailor);
        $this->order->forceFill(['escrow' => true])->save();
        $this->record('20000')->assertStatus(422);

        $this->order->forceFill(['escrow' => false, 'status' => Order::CANCELLED])->save();
        $this->record('20000')->assertStatus(422);

        $this->assertSame(0, Payment::query()->count());
    }

    /**
     * A mistyped amount can be taken back. The order does NOT move back --
     * the cloth may already be cut.
     */
    public function test_a_mistaken_entry_can_be_removed_without_unstarting_the_work(): void
    {
        Sanctum::actingAs($this->tailor);

        $id = $this->record('20000')->json('data.id');
        $this->assertSame(Order::IN_PROGRESS, $this->order->fresh()->status);

        $this->deleteJson("/api/orders/{$this->order->id}/direct-payments/{$id}")->assertOk();

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(Order::IN_PROGRESS, $this->order->fresh()->status);
    }

    public function test_a_finished_order_keeps_its_payments(): void
    {
        Sanctum::actingAs($this->tailor);

        $id = $this->record('40000')->json('data.id');
        $this->order->forceFill(['status' => Order::COMPLETED])->save();

        $this->deleteJson("/api/orders/{$this->order->id}/direct-payments/{$id}")->assertStatus(422);
        $this->assertSame(1, Payment::query()->count());
    }

    /** Only a direct entry can be removed this way, never a gateway payment. */
    public function test_a_gateway_payment_cannot_be_removed_through_this(): void
    {
        $gateway = Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $this->order->id,
            'payer_id' => $this->customer->id,
            'provider' => 'flutterwave',
            'provider_reference' => 'RC-OLD-1',
            'amount' => '1000.00',
            'status' => Payment::SUCCESSFUL,
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($this->tailor);

        $this->deleteJson("/api/orders/{$this->order->id}/direct-payments/{$gateway->id}")->assertNotFound();
        $this->assertNotNull($gateway->fresh());
    }

    /** Both of them see the list: her receipt, and the tailor's way to correct it. */
    public function test_the_order_lists_what_was_recorded(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->record('20000');

        Sanctum::actingAs($this->customer);

        $this->getJson("/api/orders/{$this->order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.direct_payments')
            ->assertJsonPath('data.direct_payments.0.amount', '20000.00')
            ->assertJsonPath('data.paid_total', '20000.00');
    }

    /** Rachels Closet cannot give back money it never received. */
    public function test_a_direct_order_cannot_be_refunded(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->record('20000');

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson("/api/admin/orders/{$this->order->id}/refund", ['amount' => '1000'])
            ->assertStatus(422);
    }
}
