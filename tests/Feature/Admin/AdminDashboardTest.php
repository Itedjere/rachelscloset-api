<?php

namespace Tests\Feature\Admin;

use App\Models\Dispute;
use App\Models\Order;
use App\Models\Payout;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\TailorProfile;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The admin dashboard, and the one power it carries.
 *
 * Two questions: is anything waiting for me, and is anything quietly broken.
 * The second is the whole reason this section exists -- nothing on this
 * platform is load-bearing on cron, which is exactly what would let a dead
 * schedule go unnoticed.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->admin()->create();
    }

    private function dashboard(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson('/api/admin/dashboard')->assertOk()->json('data');
    }

    /* ===================================================================== */

    public function test_only_an_admin_may_look(): void
    {
        foreach ([User::factory()->tailor(), User::factory()->customer()] as $factory) {
            Sanctum::actingAs($factory->create());
            $this->getJson('/api/admin/dashboard')->assertForbidden();
        }
    }

    /** A list of zeroes trains somebody to stop reading the list. */
    public function test_nothing_waiting_shows_nothing(): void
    {
        $this->assertSame([], $this->dashboard()['attention']);
    }

    /**
     * Frozen money and two people waiting on a call, so it leads the list.
     */
    public function test_an_open_dispute_leads_the_list(): void
    {
        $order = Order::factory()->create();

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $order->customer_id,
            'reason' => 'The gown does not fit.',
        ]);

        $attention = $this->dashboard()['attention'];

        $this->assertSame('open_disputes', $attention[0]['key']);
        $this->assertSame(1, $attention[0]['count']);
        $this->assertSame('bad', $attention[0]['tone']);
        $this->assertSame('/admin/disputes', $attention[0]['href']);
    }

    /** Settled means gone from the queue, not struck through in it. */
    public function test_a_settled_dispute_leaves_the_list(): void
    {
        $order = Order::factory()->create();

        Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $order->customer_id,
            'reason' => 'The gown does not fit.',
        ])->forceFill([
            'status' => Dispute::RESOLVED,
            'outcome' => Dispute::WITHDRAWN,
            'resolved_at' => now(),
        ])->save();

        $this->assertSame([], $this->dashboard()['attention']);
    }

    public function test_held_reviews_appear(): void
    {
        $order = Order::factory()->create();

        Review::create([
            'order_id' => $order->id,
            'direction' => Review::CUSTOMER_TO_TAILOR,
            'author_id' => $order->customer_id,
            'subject_id' => $order->tailor_id,
            'rating' => 5,
        ])->hold('0.00');

        $item = collect($this->dashboard()['attention'])->firstWhere('key', 'held_reviews');

        $this->assertSame(1, $item['count']);
        $this->assertSame('/admin/reviews', $item['href']);
    }

    public function test_a_failed_payout_is_flagged_as_bad(): void
    {
        $order = Order::factory()->create();
        $order->forceFill(['status' => Order::COMPLETED])->save();

        Payout::create([
            'order_id' => $order->id,
            'tailor_id' => $order->tailor_id,
            'gross_amount' => '5000.00',
            'net_amount' => '5000.00',
            'status' => Payout::FAILED,
            'failure_reason' => 'Bank refused it',
        ]);

        $item = collect($this->dashboard()['attention'])->firstWhere('key', 'failed_payouts');

        $this->assertSame(1, $item['count']);
        $this->assertSame('bad', $item['tone']);
    }

    /** The ready state doing its job: finished and not collected. */
    public function test_a_garment_past_its_collection_date_appears(): void
    {
        $order = Order::factory()->create();
        $order->forceFill([
            'status' => Order::READY,
            'ready_at' => now()->subDays(30),
            'collection_deadline' => now()->subDays(10)->toDateString(),
        ])->save();

        $item = collect($this->dashboard()['attention'])->firstWhere('key', 'overdue_collection');

        $this->assertSame(1, $item['count']);
    }

    /** Written down since Section 4 so it could be seen. This is seeing it. */
    public function test_webhook_calls_with_a_bad_signature_appear(): void
    {
        WebhookEvent::create([
            'provider' => 'flutterwave',
            'event' => 'charge.completed',
            'signature_valid' => false,
            'payload' => ['probing' => true],
            'outcome' => 'rejected',
        ]);

        $item = collect($this->dashboard()['attention'])->firstWhere('key', 'bad_webhooks');

        $this->assertSame(1, $item['count']);
        $this->assertSame('bad', $item['tone']);
    }

    /* ===================================================================== */

    /**
     * THE PROMISE FROM SECTION 2.
     *
     * "notifications_pruned_at is written by the prune command and read by
     * nobody to make a decision. It exists so a cron that has quietly stopped
     * shows on the admin dashboard rather than being discovered when the disk
     * fills."
     */
    public function test_a_command_that_has_never_run_says_so(): void
    {
        $health = collect($this->dashboard()['health']);

        /*
         * One row per scheduled command, and the count is asserted on
         * purpose: a command added to the schedule without a heartbeat here
         * is one that can stop without anybody noticing, which is the exact
         * failure this panel exists to prevent.
         */
        $this->assertCount(4, $health);
        $this->assertTrue($health->every(fn ($row) => $row['state'] === 'never'));
    }

    public function test_a_command_that_ran_last_night_is_healthy(): void
    {
        PlatformSetting::set(PlatformSetting::NOTIFICATIONS_PRUNED_AT, now()->subHours(6)->toIso8601String());

        $row = collect($this->dashboard()['health'])
            ->firstWhere('key', PlatformSetting::NOTIFICATIONS_PRUNED_AT);

        $this->assertSame('ok', $row['state']);
    }

    public function test_a_command_that_stopped_days_ago_is_stale(): void
    {
        PlatformSetting::set(PlatformSetting::PAYOUTS_RELEASED_AT, now()->subDays(4)->toIso8601String());

        $row = collect($this->dashboard()['health'])
            ->firstWhere('key', PlatformSetting::PAYOUTS_RELEASED_AT);

        $this->assertSame('stale', $row['state']);
    }

    /** A run that found nothing is still a run. */
    public function test_the_sweep_stamps_a_heartbeat_even_with_nothing_due(): void
    {
        $this->artisan('payouts:release-due')->assertSuccessful();

        $this->assertNotNull(PlatformSetting::get(PlatformSetting::PAYOUTS_RELEASED_AT));
    }

    /** A dry run proves the command runs, not that the schedule is alive. */
    public function test_a_dry_run_stamps_nothing(): void
    {
        $this->artisan('payouts:release-due --dry-run')->assertSuccessful();
        $this->artisan('subscriptions:remind --dry-run')->assertSuccessful();

        $this->assertNull(PlatformSetting::get(PlatformSetting::PAYOUTS_RELEASED_AT));
        $this->assertNull(PlatformSetting::get(PlatformSetting::SUBSCRIPTIONS_REMINDED_AT));
    }

    /* ===================================================================== */

    public function test_the_numbers_count_what_matters(): void
    {
        $tailor = User::factory()->tailor()->create();
        TailorProfile::factory()->create(['user_id' => $tailor->id]);

        $order = Order::factory()->create(['tailor_id' => $tailor->id]);
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        $numbers = $this->dashboard()['numbers'];

        $this->assertSame(1, $numbers['orders_in_progress']);
        $this->assertSame(1, $numbers['tailors']);
        // Nobody has bought a listing, so nobody is listed.
        $this->assertSame(0, $numbers['tailors_listed']);
    }
}
