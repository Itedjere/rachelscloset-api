<?php

namespace Tests\Feature\Payments;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\PlatformSetting;
use App\Models\TailorProfile;
use App\Models\User;
use App\Notifications\DisputeResolved;
use App\Notifications\OrderDisputed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Disputes, and the escrow clock they depend on.
 *
 * The platform does not adjudicate: it freezes the money and puts both phone
 * numbers in front of a person. So what is tested here is the money -- that
 * it stops moving when somebody complains, that it cannot be released by any
 * of the four routes while a dispute is open, and that the clock does not run
 * out while a parcel is still in the post.
 */
class DisputeTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        config(['services.flutterwave.sandbox' => true]);

        $this->customer = User::factory()->customer()->create();
        $this->tailor = User::factory()->tailor()->create();
        $this->admin = User::factory()->admin()->create();

        /*
         * With a verified account, so a payout can actually be sent. Without
         * one it stays `pending` rather than failing -- Section 5's rule that
         * completing an order must never fail because somebody has not
         * finished a form -- which is tested on its own below.
         */
        TailorProfile::factory()->create([
            'user_id' => $this->tailor->id,
            'bank_code' => '058',
            'bank_account_number' => '0123456789',
            'bank_account_name' => 'NGOZI OKEKE',
            'transfer_recipient' => 'RCP_test_1234',
        ]);

        PlatformSetting::set(PlatformSetting::ESCROW_HOLD_DAYS, '3');
        PlatformSetting::set(PlatformSetting::ESCROW_RECEIPT_BACKSTOP_DAYS, '21');
    }

    /** A paid escrow order the tailor has just sent. */
    private function sentOrder(string $amount = '10000.00'): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
            'amount' => $amount,
            'escrow' => true,
        ]);

        Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order->id,
            'payer_id' => $this->customer->id,
            'provider' => 'flutterwave',
            'provider_reference' => $order->reference.'-'.strtoupper(uniqid()),
            'amount' => $amount,
            'status' => Payment::SUCCESSFUL,
            'paid_at' => now(),
        ]);

        Payout::recordFor($order, $amount);

        $order->forceFill(['status' => Order::COLLECTED, 'collected_at' => now()])->save();

        return $order->fresh();
    }

    /* =====================================================================
       The clock
       ===================================================================== */

    /**
     * THE HOLE THIS CLOSED.
     *
     * `collected_at` is the tailor going to the post office. Running the
     * escrow clock from it meant the money could be gone three days later,
     * before the customer had opened the box.
     */
    public function test_the_clock_does_not_run_while_a_parcel_is_in_the_post(): void
    {
        $order = $this->sentOrder();

        // A week after dispatch, with nothing confirmed.
        $order->forceFill(['collected_at' => now()->subDays(7)])->save();

        $this->assertFalse($order->fresh()->escrowReleaseDue());
    }

    public function test_confirming_receipt_starts_the_clock(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['collected_at' => now()->subDays(7)])->save();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/received")->assertOk();

        $order = $order->fresh();
        $this->assertNotNull($order->received_at);
        $this->assertFalse($order->escrowReleaseDue());

        // Three days after she confirmed, not after dispatch.
        $order->forceFill(['received_at' => now()->subDays(4)])->save();
        $this->assertTrue($order->fresh()->escrowReleaseDue());
    }

    /** A customer who goes quiet must not strand a tailor's money for ever. */
    public function test_a_long_backstop_releases_when_she_never_confirms(): void
    {
        $order = $this->sentOrder();

        $order->forceFill(['collected_at' => now()->subDays(20)])->save();
        $this->assertFalse($order->fresh()->escrowReleaseDue());

        $order->forceFill(['collected_at' => now()->subDays(22)])->save();
        $this->assertTrue($order->fresh()->escrowReleaseDue());
    }

    public function test_confirming_receipt_twice_does_not_move_the_clock(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/received")->assertOk();
        $first = $order->fresh()->received_at;

        $this->travel(2)->days();
        $this->postJson("/api/orders/{$order->id}/received")->assertOk();

        $this->assertTrue($first->equalTo($order->fresh()->received_at));
    }

    /**
     * The local order is untouched, which was the constraint on this change.
     *
     * A customer standing in the shop has the garment the moment the tailor
     * taps, so the clock starts at once and she is paid in three days exactly
     * as before. Making every tailor wait for a receipt tap would have been
     * the cure being worse than the disease.
     */
    public function test_collecting_in_person_still_pays_in_three_days(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['status' => Order::READY, 'collected_at' => null, 'received_at' => null])->save();

        Sanctum::actingAs($this->tailor);
        $this->postJson("/api/orders/{$order->id}/collected")->assertOk();

        $order = $order->fresh();
        $this->assertNotNull($order->received_at);

        $order->forceFill([
            'collected_at' => now()->subDays(4),
            'received_at' => now()->subDays(4),
        ])->save();

        $this->assertTrue($order->fresh()->escrowReleaseDue());
    }

    /** Posting it does not. That is the whole distinction. */
    public function test_posting_it_waits_for_her_to_confirm(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['status' => Order::READY, 'collected_at' => null, 'received_at' => null])->save();

        Sanctum::actingAs($this->tailor);
        $this->postJson("/api/orders/{$order->id}/collected", ['posted' => true])->assertOk();

        $order = $order->fresh();
        $this->assertNull($order->received_at);

        $order->forceFill(['collected_at' => now()->subDays(4)])->save();
        $this->assertFalse($order->fresh()->escrowReleaseDue());
    }

    public function test_only_the_customer_confirms_receipt(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->tailor);
        $this->postJson("/api/orders/{$order->id}/received")->assertNotFound();
    }

    /* =====================================================================
       Raising one
       ===================================================================== */

    public function test_the_customer_raises_one_and_the_tailor_is_told(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/dispute", [
            'reason' => 'The sleeves are far too short and the colour is wrong.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Dispute::OPEN)
            ->assertJsonPath('data.opened_by_staff', false);

        Notification::assertSentTo($this->tailor, OrderDisputed::class);
    }

    /** She may be ringing precisely because she cannot work the app. */
    public function test_an_admin_opens_one_after_a_phone_call(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/orders/{$order->id}/dispute", [
            'reason' => 'Rang in: says the trousers do not fit at all.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.opened_by_staff', true);

        $this->assertTrue($order->fresh()->hasOpenDispute());
    }

    public function test_the_tailor_cannot_raise_one(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->tailor);

        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'She is difficult.'])
            ->assertNotFound();
    }

    /** A garment still being made is a conversation, and the tracker is it. */
    public function test_one_cannot_be_raised_before_she_has_the_garment(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'Taking too long.'])
            ->assertStatus(422);
    }

    public function test_only_one_open_dispute_per_order(): void
    {
        $order = $this->sentOrder();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'Sleeves too short.'])
            ->assertCreated();
        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'And the colour.'])
            ->assertStatus(422);

        $this->assertSame(1, Dispute::query()->count());
    }

    /* =====================================================================
       The freeze -- all four routes the money could otherwise take
       ===================================================================== */

    public function test_an_open_dispute_freezes_the_timer(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['received_at' => now()->subDays(10)])->save();

        $this->assertTrue($order->fresh()->escrowReleaseDue());

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'Sleeves too short.',
        ]);

        $this->assertFalse($order->fresh()->escrowReleaseDue());
    }

    public function test_the_tailor_cannot_release_it_herself(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['received_at' => now()->subDays(10)])->save();

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'Sleeves too short.',
        ]);

        Sanctum::actingAs($this->tailor);

        $this->postJson("/api/orders/{$order->id}/release")->assertStatus(422);
        $this->assertFalse($order->fresh()->payout->isReleased());
    }

    /** The nightly sweep must walk past it too. */
    public function test_the_sweep_leaves_a_disputed_order_alone(): void
    {
        $order = $this->sentOrder();
        $order->forceFill(['received_at' => now()->subDays(10)])->save();

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'Sleeves too short.',
        ]);

        $this->artisan('payouts:release-due')->assertSuccessful();

        $this->assertFalse($order->fresh()->payout->isReleased());
    }

    /** And she cannot accidentally hand it over by confirming she is happy. */
    public function test_confirming_happiness_is_refused_while_a_dispute_is_open(): void
    {
        $order = $this->sentOrder();

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'Sleeves too short.',
        ]);

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/confirm")->assertStatus(422);
        $this->assertFalse($order->fresh()->payout->isReleased());
    }

    /* =====================================================================
       Settling it
       ===================================================================== */

    private function openDispute(Order $order): Dispute
    {
        return Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'The sleeves are far too short.',
        ]);
    }

    /** Both numbers, because settling one is two phone calls. */
    public function test_the_queue_carries_both_phone_numbers_and_what_is_held(): void
    {
        $order = $this->sentOrder();
        $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/disputes')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.customer.phone', $this->customer->phone)
            ->assertJsonPath('data.0.tailor.phone', $this->tailor->phone)
            ->assertJsonPath('data.0.order.held', '10000.00');
    }

    public function test_a_full_refund_sends_everything_back_and_pays_the_tailor_nothing(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'outcome' => Dispute::REFUNDED,
            'note' => 'Agreed on the call: garment unusable.',
        ])->assertOk();

        $payout = $order->fresh()->payout;

        $this->assertSame('10000.00', (string) $payout->refunded_amount);
        $this->assertSame('0.00', (string) $payout->net_amount);
        $this->assertFalse($payout->isReleased());
        $this->assertSame(Order::COMPLETED, $order->fresh()->status);
    }

    /** The split most real negotiations end in. */
    public function test_a_partial_refund_pays_the_tailor_the_balance(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'outcome' => Dispute::REFUNDED,
            'amount' => '4000',
            'note' => 'Agreed: she keeps it, tailor takes less.',
        ])->assertOk();

        $payout = $order->fresh()->payout;

        $this->assertSame('4000.00', (string) $payout->refunded_amount);
        $this->assertSame('6000.00', (string) $payout->net_amount);
        $this->assertTrue($payout->isReleased());
        $this->assertSame('4000.00', (string) $dispute->fresh()->refunded_amount);
    }

    public function test_finding_for_the_tailor_pays_her_in_full(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'outcome' => Dispute::RELEASED,
            'note' => 'The work was as agreed.',
        ])->assertOk();

        $this->assertTrue($order->fresh()->payout->isReleased());
        $this->assertSame('10000.00', (string) $order->fresh()->payout->net_amount);
    }

    /** Not a judgement: the parcel turned up after all. */
    public function test_a_withdrawn_dispute_moves_no_money(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'outcome' => Dispute::WITHDRAWN,
            'note' => 'Rang back: it arrived and she is happy.',
        ])->assertOk();

        $payout = $order->fresh()->payout;

        $this->assertFalse($payout->isReleased());
        $this->assertSame('0.00', (string) $payout->refunded_amount);
    }

    public function test_both_sides_are_told_the_same_thing(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", [
            'outcome' => Dispute::RELEASED,
        ])->assertOk();

        Notification::assertSentTo($this->customer, DisputeResolved::class);
        Notification::assertSentTo($this->tailor, DisputeResolved::class);
    }

    public function test_a_settled_dispute_cannot_be_settled_again(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", ['outcome' => Dispute::RELEASED])
            ->assertOk();
        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", ['outcome' => Dispute::REFUNDED])
            ->assertStatus(422);
    }

    public function test_settling_unfreezes_the_order(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        $this->assertTrue($order->fresh()->hasOpenDispute());

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", ['outcome' => Dispute::RELEASED])
            ->assertOk();

        $this->assertFalse($order->fresh()->hasOpenDispute());
    }

    public function test_nobody_but_an_admin_settles_one(): void
    {
        $order = $this->sentOrder();
        $dispute = $this->openDispute($order);

        foreach ([$this->customer, $this->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", ['outcome' => Dispute::RELEASED])
                ->assertForbidden();
        }
    }

    /**
     * A tailor with no bank details still wins her dispute.
     *
     * The money is recorded as owed and the payout stays pending, exactly as
     * it would on an ordinary completion. Settling a dispute must not be the
     * one place that fails because somebody has not finished a form.
     */
    public function test_finding_for_a_tailor_with_no_bank_details_leaves_it_owed(): void
    {
        $other = User::factory()->tailor()->create();

        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $other->id,
            'amount' => '5000.00',
            'escrow' => true,
        ]);

        Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order->id,
            'payer_id' => $this->customer->id,
            'provider' => 'flutterwave',
            'provider_reference' => $order->reference.'-'.strtoupper(uniqid()),
            'amount' => '5000.00',
            'status' => Payment::SUCCESSFUL,
            'paid_at' => now(),
        ]);
        Payout::recordFor($order, '5000.00');
        $order->forceFill(['status' => Order::COLLECTED, 'collected_at' => now()])->save();

        $dispute = Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $this->customer->id,
            'reason' => 'Not what I asked for.',
        ]);

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/disputes/{$dispute->id}/resolve", ['outcome' => Dispute::RELEASED])
            ->assertOk();

        $payout = $order->fresh()->payout;

        $this->assertFalse($payout->isReleased());
        $this->assertSame('5000.00', (string) $payout->net_amount);
        $this->assertSame(Dispute::RESOLVED, $dispute->fresh()->status);
    }

    /**
     * Once the tailor has the money, taking it back means the platform pays
     * twice. That is a conversation, not a button -- and RefundOrder has
     * refused it since Section 5.
     */
    public function test_a_dispute_cannot_be_raised_after_the_tailor_has_been_paid(): void
    {
        $order = $this->sentOrder();
        $order->payout->forceFill(['status' => Payout::RELEASED, 'released_at' => now()])->save();

        Sanctum::actingAs($this->customer);

        $this->postJson("/api/orders/{$order->id}/dispute", ['reason' => 'Too late, sadly.'])
            ->assertStatus(422);
    }
}
