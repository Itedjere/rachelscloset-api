<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\User;
use App\Notifications\OrderRefunded;
use App\Services\Payments\ReleasePayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.flutterwave.sandbox' => true]);
        Notification::fake();
    }

    /** An escrow order that has been paid in full and not yet released. */
    private function paidOrder(): Order
    {
        $order = Order::factory()->escrow()->create(['amount' => '25000.00']);

        Payment::factory()->successful()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '25000.00',
        ]);

        Payout::recordFor($order, '25000.00');

        return $order->fresh();
    }

    private function asAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /** Partial is the common case, not the exception. */
    public function test_a_partial_refund_reduces_what_the_tailor_is_owed(): void
    {
        $order = $this->paidOrder();
        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", [
            'amount' => 5000,
            'reason' => 'Delivered four days late.',
        ])
            ->assertOk()
            ->assertJsonPath('data.refunded_total', '5000.00')
            ->assertJsonPath('data.tailor_now_owed', '20000.00');

        Notification::assertSentTo($order->customer, OrderRefunded::class);
    }

    public function test_refunds_accumulate(): void
    {
        $order = $this->paidOrder();
        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 5000])->assertOk();
        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 2500])
            ->assertOk()
            ->assertJsonPath('data.refunded_total', '7500.00')
            ->assertJsonPath('data.tailor_now_owed', '17500.00');
    }

    public function test_a_refund_cannot_exceed_what_was_paid(): void
    {
        $order = $this->paidOrder();
        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 30000])
            ->assertStatus(422);

        $this->assertSame('0.00', $order->payout->fresh()->refunded_amount);
    }

    /**
     * Once the money has gone to the tailor, taking it back from the customer
     * means the platform pays twice. That is a conversation, not a button.
     */
    public function test_a_released_payout_cannot_be_refunded(): void
    {
        $order = $this->paidOrder();
        $order->payout->forceFill(['status' => Payout::RELEASED, 'released_at' => now()])->save();

        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 1000])
            ->assertStatus(422);
    }

    public function test_an_unpaid_order_has_nothing_to_refund(): void
    {
        $order = Order::factory()->escrow()->create(['amount' => '25000.00']);
        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 1000])
            ->assertStatus(422);
    }

    public function test_only_an_admin_refunds(): void
    {
        $order = $this->paidOrder();

        foreach ([$order->customer, $order->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 1000])
                ->assertForbidden();
        }
    }

    /** A fully refunded order leaves the tailor owed nothing, and releases cleanly. */
    public function test_a_full_refund_leaves_nothing_to_release(): void
    {
        $order = $this->paidOrder();
        $this->asAdmin();

        $this->postJson("/api/admin/orders/{$order->id}/refund", ['amount' => 25000])
            ->assertOk()
            ->assertJsonPath('data.tailor_now_owed', '0.00');

        app(ReleasePayout::class)->handle($order->payout->fresh());

        // Released with nothing sent, rather than stuck pending forever.
        $this->assertSame(Payout::RELEASED, $order->payout->fresh()->status);
    }
}
