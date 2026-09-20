<?php

namespace Tests\Feature\Payments;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\CollectionReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Reminding somebody to come and collect her clothes.
 *
 * The brief's second problem. `ready` and the collection deadline have
 * existed since Section 5 for the sake of this command, and what is tested
 * here is mostly restraint: that it says the right thing once, that a cron
 * misconfigured the ordinary way does not say it twice, and above all that an
 * uncollected garment does not generate a notification every night for ever.
 */
class CollectionReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->customer = User::factory()->customer()->create([
            'name' => 'Amaka Eze',
            'phone' => '08031112233',
        ]);
        $this->tailor = User::factory()->tailor()->create(['name' => 'Mama Ngozi']);
    }

    /** A finished garment whose deadline is $days away. Negative is overdue. */
    private function waiting(int $days, string $amount = '25000.00'): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
            'garment_type_id' => GarmentType::factory()->create(['name' => 'Lace Gown']),
            'amount' => $amount,
        ]);

        $order->forceFill([
            'status' => Order::READY,
            'ready_at' => now()->subDays(14),
            'collection_deadline' => now()->addDays($days)->toDateString(),
        ])->save();

        return $order;
    }

    private function payInFull(Order $order): void
    {
        Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order->id,
            'payer_id' => $this->customer->id,
            'provider' => 'flutterwave',
            'provider_reference' => $order->reference.'-PAID',
            'amount' => $order->amount,
            'status' => Payment::SUCCESSFUL,
            'paid_at' => now(),
        ]);
    }

    /** The message the customer was actually sent. */
    private function customerMessage(): string
    {
        $found = null;

        Notification::assertSentTo(
            $this->customer,
            CollectionReminder::class,
            function (CollectionReminder $n) use (&$found) {
                $found = $n->payload($this->customer)['message'];

                return true;
            },
        );

        return $found;
    }

    /* ===================================================================== */

    public function test_nothing_waiting_tells_nobody(): void
    {
        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** Still a fortnight out. She was told when it became ready; that is enough. */
    public function test_an_order_far_from_its_deadline_is_left_alone(): void
    {
        $this->waiting(10);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_three_days_out_the_customer_is_nudged_and_the_tailor_is_not(): void
    {
        $order = $this->waiting(3);
        $this->payInFull($order);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertSentTo($this->customer, CollectionReminder::class);
        Notification::assertNotSentTo($this->tailor, CollectionReminder::class);

        $this->assertSame(3, $order->fresh()->collection_reminded_days);
        $this->assertStringContainsString('Mama Ngozi', $this->customerMessage());
    }

    public function test_one_day_out_it_names_the_last_day(): void
    {
        $order = $this->waiting(1);
        $this->payInFull($order);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertStringContainsString(
            'last day to collect it is '.now()->addDay()->format('j F'),
            $this->customerMessage(),
        );
        $this->assertSame(1, $order->fresh()->collection_reminded_days);
    }

    /**
     * NO MESSAGE SAYS "TOMORROW".
     *
     * This command is allowed to miss a day -- that is what makes it a
     * courtesy rather than an invariant -- so a relative word is a lie
     * waiting for the first night the cron does not run. Every message names
     * the date instead, which is true whenever it happens to arrive.
     */
    public function test_no_reminder_uses_a_relative_day(): void
    {
        foreach ([3, 1, -1] as $days) {
            Notification::fake();
            $this->waiting($days);

            $this->artisan('orders:remind-collection')->assertSuccessful();

            $this->assertStringNotContainsStringIgnoringCase('tomorrow', $this->customerMessage());
            $this->assertStringNotContainsStringIgnoringCase('today', $this->customerMessage());
        }
    }

    /**
     * The deadline day is not overdue.
     *
     * `$left` reaches zero at midnight on the day she may still collect, so
     * treating zero as a milestone told her the garment "was due" on the very
     * day it was due. Overdue now begins the day after.
     */
    public function test_the_deadline_day_itself_is_not_called_overdue(): void
    {
        $order = $this->waiting(0);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertStringNotContainsString('was due', $this->customerMessage());
        Notification::assertNotSentTo($this->tailor, CollectionReminder::class);
        $this->assertSame(1, $order->fresh()->collection_reminded_days);
    }

    /** And the day after it is, to both of them. */
    public function test_the_day_after_the_deadline_is_overdue(): void
    {
        $order = $this->waiting(-1);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertStringContainsString('was due to be collected', $this->customerMessage());
        Notification::assertSentTo($this->tailor, CollectionReminder::class);
        $this->assertSame(0, $order->fresh()->collection_reminded_days);
    }

    /* ===================================================================== */

    /**
     * A cron firing twice in a day is the ordinary way these are
     * misconfigured, and the column exists for exactly this.
     */
    public function test_a_second_run_the_same_day_says_nothing_more(): void
    {
        $order = $this->waiting(3);

        $this->artisan('orders:remind-collection')->assertSuccessful();
        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertSentToTimes($this->customer, CollectionReminder::class, 1);
        $this->assertSame(3, $order->fresh()->collection_reminded_days);
    }

    /**
     * A cron down for days sends the nearest milestone, not a burst of stale
     * ones. Telling her "three days left" about a garment already overdue
     * would be worse than saying nothing.
     */
    public function test_a_missed_run_sends_the_smallest_milestone_owed(): void
    {
        $order = $this->waiting(1);
        $order->forceFill(['collection_reminded_days' => null])->save();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertSentToTimes($this->customer, CollectionReminder::class, 1);
        $this->assertSame(1, $order->fresh()->collection_reminded_days);
    }

    public function test_a_nearer_milestone_is_told_again(): void
    {
        $order = $this->waiting(1);
        $order->forceFill(['collection_reminded_days' => 3])->save();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertSentTo($this->customer, CollectionReminder::class);
        $this->assertSame(1, $order->fresh()->collection_reminded_days);
    }

    /* ===================================================================== */

    /** At the end, and only then, the tailor gets the phone number. */
    public function test_overdue_tells_both_and_gives_the_tailor_her_number(): void
    {
        $order = $this->waiting(-1);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertSentTo($this->customer, CollectionReminder::class);
        Notification::assertSentTo(
            $this->tailor,
            CollectionReminder::class,
            function (CollectionReminder $n) {
                $message = $n->payload($this->tailor)['message'];

                return str_contains($message, 'Amaka Eze')
                    && str_contains($message, '08031112233');
            },
        );

        $this->assertSame(0, $order->fresh()->collection_reminded_days);
    }

    /**
     * THE ONE THAT MATTERS MOST.
     *
     * An uncollected garment never resolves itself the way a lapsed
     * subscription does, so without a terminal milestone this command would
     * notify the same two people every night indefinitely -- which is how
     * somebody learns to ignore all of them, including the ones about her
     * next order.
     */
    public function test_an_order_left_overdue_for_a_week_is_told_once(): void
    {
        $this->waiting(-1);

        foreach (range(1, 7) as $ignored) {
            $this->artisan('orders:remind-collection')->assertSuccessful();
        }

        Notification::assertSentToTimes($this->customer, CollectionReminder::class, 1);
        Notification::assertSentToTimes($this->tailor, CollectionReminder::class, 1);
    }

    /* ===================================================================== */

    /**
     * "Won't collect OR CAN'T PAY" is one problem, not two. Finding out at
     * the counter and going home again is the failure this avoids.
     */
    public function test_an_outstanding_balance_is_named_days_ahead(): void
    {
        $order = $this->waiting(3, '25000.00');

        Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order->id,
            'payer_id' => $this->customer->id,
            'provider' => 'flutterwave',
            'provider_reference' => $order->reference.'-DEP',
            'amount' => '10000.00',
            'status' => Payment::SUCCESSFUL,
            'paid_at' => now(),
        ]);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertStringContainsString('15,000.00', $this->customerMessage());
    }

    public function test_a_fully_paid_order_is_not_asked_for_money(): void
    {
        $order = $this->waiting(3);
        $this->payInFull($order);

        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertStringNotContainsString('to pay', $this->customerMessage());
    }

    /* ===================================================================== */

    /** Only a finished garment is waiting for anybody. */
    public function test_only_ready_orders_are_chased(): void
    {
        foreach ([Order::IN_PROGRESS, Order::COLLECTED, Order::COMPLETED, Order::CANCELLED] as $status) {
            $order = $this->waiting(-1);
            $order->forceFill(['status' => $status])->save();
        }

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** Nothing to count down to. */
    public function test_an_order_with_no_deadline_is_skipped(): void
    {
        $order = $this->waiting(-1);
        $order->forceFill(['collection_deadline' => null])->save();

        $this->artisan('orders:remind-collection')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /* ===================================================================== */

    public function test_a_dry_run_tells_nobody_and_records_nothing(): void
    {
        $order = $this->waiting(-1);

        $this->artisan('orders:remind-collection --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($order->fresh()->collection_reminded_days);

        // A dry run proves the command can be invoked, not that the schedule
        // is alive, so it must not stamp the heartbeat the dashboard reads.
        $this->assertNull(PlatformSetting::get(PlatformSetting::COLLECTION_REMINDED_AT));
    }

    /** The heartbeat is stamped even when there was nobody to remind. */
    public function test_a_real_run_stamps_the_heartbeat(): void
    {
        $this->artisan('orders:remind-collection')->assertSuccessful();

        $this->assertNotNull(PlatformSetting::get(PlatformSetting::COLLECTION_REMINDED_AT));
    }
}
