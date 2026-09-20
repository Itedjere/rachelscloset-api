<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payout;
use App\Models\TailorProfile;
use App\Models\User;
use App\Notifications\PayoutReleased;
use App\Services\Payments\ReleasePayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The sandbox transfer gateway, which always succeeds.
        config(['services.flutterwave.sandbox' => true]);
        Notification::fake();
    }

    /** An order that has been collected, with escrow held and a payout owed. */
    private function collectedEscrowOrder(bool $withBank = true): Order
    {
        $tailor = User::factory()->tailor()->create();

        TailorProfile::factory()->create([
            'user_id' => $tailor->id,
        ] + ($withBank ? [
            'bank_code' => '058',
            'bank_account_number' => '0123456789',
            'bank_account_name' => 'NGOZI OKEKE',
            'transfer_recipient' => 'FLW:058:0123456789',
        ] : []));

        $order = Order::factory()->escrow()->create([
            'tailor_id' => $tailor->id,
            'amount' => '25000.00',
        ]);

        /*
         * Collected in person, so she had it the moment the tailor tapped --
         * which is what `received_at` records. The escrow clock runs from
         * receipt rather than from dispatch, so that a posted garment cannot
         * pay out while it is still in the van.
         */
        $order->forceFill([
            'status' => Order::COLLECTED,
            'collected_at' => now()->subDays(5),
            'received_at' => now()->subDays(5),
        ])->save();

        Payout::recordFor($order, '25000.00');

        return $order->fresh();
    }

    public function test_a_customer_confirming_releases_the_money_at_once(): void
    {
        $order = $this->collectedEscrowOrder();

        Sanctum::actingAs($order->customer);

        $this->postJson("/api/orders/{$order->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', Order::COMPLETED);

        $payout = $order->payout->fresh();

        $this->assertSame(Payout::RELEASED, $payout->status);
        $this->assertNotNull($payout->released_at);
        Notification::assertSentTo($order->tailor, PayoutReleased::class);
    }

    public function test_only_the_customer_confirms(): void
    {
        $order = $this->collectedEscrowOrder();

        Sanctum::actingAs($order->tailor);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertNotFound();
    }

    /**
     * The money must not be stuck behind a cron.
     *
     * A tailor can release it herself once the waiting period has run out, so
     * a sweep that stops running costs a tap rather than her wages.
     */
    public function test_a_tailor_can_release_once_the_wait_is_over(): void
    {
        $order = $this->collectedEscrowOrder();

        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/release")
            ->assertOk()
            ->assertJsonPath('data.status', Order::COMPLETED);

        $this->assertSame(Payout::RELEASED, $order->payout->fresh()->status);
    }

    public function test_a_tailor_cannot_release_during_the_waiting_period(): void
    {
        $order = $this->collectedEscrowOrder();
        $order->forceFill(['collected_at' => now(), 'received_at' => now()])->save();

        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/release")->assertStatus(422);
        $this->assertSame(Payout::PENDING, $order->payout->fresh()->status);
    }

    /**
     * An unfinished profile is a normal state, not an error. Completing an
     * order must never fail because somebody has not filled in a form.
     */
    public function test_no_bank_details_leaves_the_payout_pending_not_failed(): void
    {
        $order = $this->collectedEscrowOrder(withBank: false);

        Sanctum::actingAs($order->customer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $payout = $order->payout->fresh();

        $this->assertSame(Payout::PENDING, $payout->status);
        $this->assertStringContainsString('bank account', $payout->failure_reason);
        // The order still completed. The money is still owed.
        $this->assertSame(Order::COMPLETED, $order->fresh()->status);
    }

    public function test_releasing_twice_sends_once(): void
    {
        $order = $this->collectedEscrowOrder();
        $release = app(ReleasePayout::class);

        $release->handle($order->payout);
        $first = $order->payout->fresh()->released_at;

        $this->travel(5)->minutes();
        $release->handle($order->payout->fresh());

        $this->assertEquals($first, $order->payout->fresh()->released_at);
        Notification::assertSentToTimes($order->tailor, PayoutReleased::class, 1);
    }

    public function test_a_direct_order_has_nothing_to_release(): void
    {
        $order = Order::factory()->create(['escrow' => false]);
        $order->forceFill([
            'status' => Order::COLLECTED,
            'collected_at' => now()->subDays(5),
            'received_at' => now()->subDays(5),
        ])->save();

        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/release")->assertStatus(422);
    }

    /**
     * CLAUDE.md section 3: a lapse hides her from the directory and nothing
     * else. Money owed is owed.
     */
    public function test_release_does_not_depend_on_anything_but_the_order(): void
    {
        $order = $this->collectedEscrowOrder();

        // Even suspended, the work was done and the money is hers.
        $order->tailor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        app(ReleasePayout::class)->handle($order->payout);

        $this->assertSame(Payout::RELEASED, $order->payout->fresh()->status);
    }

    public function test_the_sweep_releases_what_is_due_and_leaves_the_rest(): void
    {
        $due = $this->collectedEscrowOrder();
        $notYet = $this->collectedEscrowOrder();
        $notYet->forceFill(['collected_at' => now(), 'received_at' => now()])->save();

        $this->artisan('payouts:release-due')->assertSuccessful();

        $this->assertSame(Payout::RELEASED, $due->payout->fresh()->status);
        $this->assertSame(Payout::PENDING, $notYet->payout->fresh()->status);
    }

    public function test_a_dry_run_sends_nothing(): void
    {
        $order = $this->collectedEscrowOrder();

        $this->artisan('payouts:release-due --dry-run')->assertSuccessful();

        $this->assertSame(Payout::PENDING, $order->payout->fresh()->status);
    }
}
