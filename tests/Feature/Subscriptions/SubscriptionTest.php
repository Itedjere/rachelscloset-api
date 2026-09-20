<?php

namespace Tests\Feature\Subscriptions;

use App\Models\Order;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTerm;
use App\Models\TailorProfile;
use App\Models\User;
use App\Notifications\SubscriptionExpiring;
use App\Notifications\SubscriptionLapsed;
use App\Services\Directory\TailorRanking;
use App\Services\Payments\ConfirmPayment;
use App\Services\Subscriptions\StartTerm;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Prepaid terms, not recurring billing.
 *
 * The five tests the plan names are all here: a term appears on payment; a
 * replayed webhook mints no second term; a yearly bought over a monthly
 * stacks; changing the price moves no term already sold; and a subscription
 * whose term expired without anything recomputing its status is still out of
 * the directory.
 */
class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tailor = User::factory()->tailor()->create();
        TailorProfile::factory()->create([
            'user_id' => $this->tailor->id,
            'business_name' => 'Mama Ngozi Couture',
            'slug' => 'mama-ngozi-couture',
            'state' => 'Lagos',
        ]);

        config(['services.flutterwave.sandbox' => true]);

        foreach ([
            PlatformSetting::SUBSCRIPTION_PRICE_MONTHLY => '2000',
            PlatformSetting::SUBSCRIPTION_PRICE_YEARLY => '20000',
            PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS => '30',
            PlatformSetting::SUBSCRIPTION_YEARLY_DAYS => '365',
            PlatformSetting::SUBSCRIPTION_GRACE_DAYS => '7',
            PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION => '1',
        ] as $key => $value) {
            PlatformSetting::set($key, $value);
        }
    }

    /** A payment that has already settled, as the gateway would report it. */
    private function paidFor(string $plan, string $amount): Payment
    {
        $subscription = Subscription::forTailor($this->tailor);

        return Payment::create([
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'subscription_id' => $subscription->id,
            'plan' => $plan,
            'payer_id' => $this->tailor->id,
            'provider' => 'flutterwave',
            'provider_reference' => 'RC-SUB-'.strtoupper(uniqid()),
            'amount' => $amount,
            'status' => Payment::PENDING,
        ]);
    }

    private function confirm(Payment $payment): void
    {
        app(ConfirmPayment::class)->handle($payment->provider_reference);
    }

    /* ===================================================================== */

    public function test_paying_mints_a_term(): void
    {
        $payment = $this->paidFor(Subscription::MONTHLY, '2000');

        $this->confirm($payment);

        $term = SubscriptionTerm::query()->sole();

        $this->assertSame(30, $term->days);
        $this->assertSame('2000.00', $term->amount);
        $this->assertSame($payment->id, $term->payment_id);

        $subscription = Subscription::query()->sole();

        $this->assertTrue($subscription->covers());
        $this->assertSame(Subscription::ACTIVE, $subscription->status);
        $this->assertTrue($subscription->current_period_end->isFuture());
    }

    /**
     * THE IDEMPOTENCY TEST.
     *
     * Flutterwave replays webhooks, and a customer refreshes the return page.
     * Either can arrive twice, in either order.
     */
    public function test_a_replayed_confirmation_mints_no_second_term(): void
    {
        $payment = $this->paidFor(Subscription::MONTHLY, '2000');

        $this->confirm($payment);
        $end = Subscription::query()->sole()->current_period_end;

        $this->confirm($payment);
        $this->confirm($payment);

        $this->assertSame(1, SubscriptionTerm::query()->count());
        $this->assertTrue($end->equalTo(Subscription::query()->sole()->fresh()->current_period_end));
    }

    /** The constraint is the rule, not just the check in front of it. */
    public function test_the_database_refuses_a_second_term_for_one_payment(): void
    {
        $payment = $this->paidFor(Subscription::MONTHLY, '2000');
        $this->confirm($payment);

        $this->expectException(QueryException::class);

        SubscriptionTerm::create([
            'subscription_id' => Subscription::query()->sole()->id,
            'payment_id' => $payment->id,
            'plan' => Subscription::MONTHLY,
            'days' => 30,
            'amount' => '2000.00',
            'starts_at' => now(),
            'ends_at' => now()->addDays(30),
        ]);
    }

    /**
     * THE STACKING TEST.
     *
     * Monthly to yearly is "buy a yearly": it starts when the current term
     * ends, so upgrading costs her nothing and there is no proration.
     */
    public function test_a_yearly_bought_over_a_running_monthly_stacks(): void
    {
        $this->confirm($this->paidFor(Subscription::MONTHLY, '2000'));

        $afterMonthly = Subscription::query()->sole()->current_period_end;

        $this->confirm($this->paidFor(Subscription::YEARLY, '20000'));

        $terms = SubscriptionTerm::query()->orderBy('starts_at')->get();

        $this->assertCount(2, $terms);
        // The second begins where the first ended, not today.
        $this->assertTrue($terms[1]->starts_at->equalTo($terms[0]->ends_at));

        $end = Subscription::query()->sole()->fresh()->current_period_end;

        $this->assertTrue($end->greaterThan($afterMonthly));
        $this->assertSame(395, (int) round(now()->diffInDays($end)));
    }

    /**
     * THE PRICE-CHANGE TEST.
     *
     * Terms are a ledger. An admin raising the price must not reach back and
     * move days somebody has already bought.
     */
    public function test_changing_the_price_moves_no_term_already_sold(): void
    {
        $this->confirm($this->paidFor(Subscription::MONTHLY, '2000'));

        $term = SubscriptionTerm::query()->sole();
        $endsAt = $term->ends_at;

        PlatformSetting::set(PlatformSetting::SUBSCRIPTION_PRICE_MONTHLY, '5000');
        PlatformSetting::set(PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS, '7');

        $term = $term->fresh();

        $this->assertSame('2000.00', $term->amount);
        $this->assertSame(30, $term->days);
        $this->assertTrue($endsAt->equalTo($term->ends_at));
        $this->assertTrue($endsAt->equalTo(Subscription::query()->sole()->current_period_end));
    }

    /* ===================================================================== */

    /**
     * THE DRIFT TEST, and the reason `status` is only a label.
     *
     * The timestamp has passed; nothing has recomputed the enum, so it still
     * reads "active". The directory must not believe it.
     */
    public function test_an_expired_term_is_out_of_the_directory_even_with_a_stale_status(): void
    {
        $subscription = Subscription::forTailor($this->tailor);

        $subscription->forceFill([
            'current_period_end' => now()->subDays(30),
            'grace_ends_at' => now()->subDays(23),
            // Deliberately wrong, as a dead cron would leave it.
            'status' => Subscription::ACTIVE,
        ])->save();

        $this->assertSame(Subscription::ACTIVE, $subscription->fresh()->status);
        $this->assertFalse($subscription->fresh()->covers());

        $this->assertCount(0, app(TailorRanking::class)->search(null, null));
        $this->get('/tailors')->assertOk()->assertDontSee('Mama Ngozi Couture');
    }

    public function test_a_subscribed_tailor_is_listed(): void
    {
        $this->confirm($this->paidFor(Subscription::MONTHLY, '2000'));

        $this->get('/tailors')->assertOk()->assertSee('Mama Ngozi Couture');
    }

    public function test_a_tailor_who_never_subscribed_is_not_listed(): void
    {
        $this->get('/tailors')->assertOk()->assertDontSee('Mama Ngozi Couture');
    }

    /** Grace is not a lapse: a bank transfer settling late must not delist her. */
    public function test_grace_keeps_her_listed(): void
    {
        $subscription = Subscription::forTailor($this->tailor);

        $subscription->forceFill([
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => now()->addDays(6),
        ])->save();

        $this->assertTrue($subscription->fresh()->covers());
        $this->assertSame(Subscription::GRACE, $subscription->fresh()->syncStatus()->status);
        $this->get('/tailors')->assertOk()->assertSee('Mama Ngozi Couture');
    }

    /**
     * A lapse hides her from the directory AND NOTHING ELSE.
     *
     * Her own page keeps resolving, because it is printed on cardboard that
     * cannot be reissued -- see Section 16.
     */
    public function test_a_lapse_does_not_kill_the_page_a_printed_card_points_at(): void
    {
        $this->get('/t/mama-ngozi-couture')
            ->assertOk()
            ->assertSee('Mama Ngozi Couture')
            ->assertSee('not listed at the moment');
    }

    /** The cold-start escape hatch, off by default. */
    public function test_the_gate_can_be_turned_off_for_an_introductory_period(): void
    {
        PlatformSetting::set(PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION, '0');

        $this->get('/tailors')->assertOk()->assertSee('Mama Ngozi Couture');
        $this->get('/t/mama-ngozi-couture')->assertOk()->assertDontSee('not listed at the moment');
    }

    /* ===================================================================== */

    public function test_she_sees_what_it_costs_and_where_she_stands(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('data.listed', false)
            ->assertJsonPath('data.status', Subscription::LAPSED)
            ->assertJsonPath('data.plans.0.plan', Subscription::MONTHLY)
            ->assertJsonPath('data.plans.0.price', '2000')
            ->assertJsonPath('data.plans.1.days', 365);
    }

    public function test_paying_returns_a_link_and_records_the_plan(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/subscription/pay', ['plan' => Subscription::YEARLY])
            ->assertOk()
            ->assertJsonStructure(['data' => ['reference', 'amount', 'link']])
            ->assertJsonPath('data.amount', '20000.00');

        $payment = Payment::query()->sole();

        $this->assertSame(Subscription::YEARLY, $payment->plan);
        $this->assertSame(Payment::PURPOSE_SUBSCRIPTION, $payment->purpose);
    }

    public function test_a_customer_has_no_subscription(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->getJson('/api/subscription')->assertNotFound();
        $this->postJson('/api/subscription/pay', ['plan' => Subscription::MONTHLY])->assertNotFound();
    }

    /** Renewing early is allowed: the days stack, so nothing is lost. */
    public function test_she_may_buy_while_a_term_is_still_running(): void
    {
        $this->confirm($this->paidFor(Subscription::MONTHLY, '2000'));

        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/subscription/pay', ['plan' => Subscription::MONTHLY])->assertOk();
    }

    /* ===================================================================== */

    public function test_an_admin_can_grant_days_without_a_payment(): void
    {
        $admin = User::factory()->admin()->create();

        app(StartTerm::class)->grant($this->tailor, Subscription::MONTHLY, $admin, 'Founding tailor');

        $term = SubscriptionTerm::query()->sole();

        $this->assertNull($term->payment_id);
        $this->assertSame('0.00', $term->amount);
        $this->assertSame($admin->id, $term->granted_by);
        $this->assertTrue(Subscription::query()->sole()->covers());
    }

    /** Many granted terms, because the unique index permits many NULLs. */
    public function test_granted_terms_stack_like_any_other(): void
    {
        app(StartTerm::class)->grant($this->tailor, Subscription::MONTHLY);
        app(StartTerm::class)->grant($this->tailor, Subscription::MONTHLY);

        $this->assertSame(2, SubscriptionTerm::query()->count());
        $this->assertSame(60, (int) round(now()->diffInDays(Subscription::query()->sole()->current_period_end)));
    }

    /* ===================================================================== */

    public function test_the_reminder_tells_her_once_per_milestone(): void
    {
        $subscription = Subscription::forTailor($this->tailor);
        // Just inside three days: ceil() of the remainder is 3, so the
        // three-day milestone is the smallest one owed.
        $subscription->forceFill(['current_period_end' => now()->addDays(3)->subMinute()])->save();

        $this->artisan('subscriptions:remind')->assertSuccessful();
        // A cron firing twice in a day must not tell her twice.
        $this->artisan('subscriptions:remind')->assertSuccessful();

        Notification::assertSentToTimes($this->tailor, SubscriptionExpiring::class, 1);
        $this->assertSame(3, $subscription->fresh()->last_reminder_days);
    }

    public function test_a_nearer_milestone_is_told_again(): void
    {
        $subscription = Subscription::forTailor($this->tailor);
        $subscription->forceFill([
            'current_period_end' => now()->addDay()->subMinute(),
            'last_reminder_days' => 7,
        ])->save();

        $this->artisan('subscriptions:remind')->assertSuccessful();

        Notification::assertSentTo($this->tailor, SubscriptionExpiring::class);
        $this->assertSame(1, $subscription->fresh()->last_reminder_days);
    }

    public function test_a_lapse_is_announced_once(): void
    {
        $subscription = Subscription::forTailor($this->tailor);
        $subscription->forceFill([
            'current_period_end' => now()->subDays(10),
            'grace_ends_at' => now()->subDays(3),
            'status' => Subscription::ACTIVE,
        ])->save();

        $this->artisan('subscriptions:remind')->assertSuccessful();
        $this->artisan('subscriptions:remind')->assertSuccessful();

        Notification::assertSentToTimes($this->tailor, SubscriptionLapsed::class, 1);
        $this->assertSame(Subscription::LAPSED, $subscription->fresh()->status);
    }

    /** Grace is not a lapse, and must not be announced as one. */
    public function test_grace_is_not_announced_as_a_lapse(): void
    {
        $subscription = Subscription::forTailor($this->tailor);
        $subscription->forceFill([
            'current_period_end' => now()->subDay(),
            'grace_ends_at' => now()->addDays(6),
            'status' => Subscription::ACTIVE,
        ])->save();

        $this->artisan('subscriptions:remind')->assertSuccessful();

        Notification::assertNotSentTo($this->tailor, SubscriptionLapsed::class);
    }

    public function test_a_dry_run_tells_nobody(): void
    {
        $subscription = Subscription::forTailor($this->tailor);
        $subscription->forceFill(['current_period_end' => now()->addDay()->subMinute()])->save();

        $this->artisan('subscriptions:remind --dry-run')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($subscription->fresh()->last_reminder_days);
    }

    /* ===================================================================== */

    /** Money owed is owed: a lapse touches nothing but the listing. */
    public function test_a_lapse_does_not_touch_her_orders_or_her_money(): void
    {
        $order = Order::factory()->create(['tailor_id' => $this->tailor->id]);
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        $subscription = Subscription::forTailor($this->tailor);
        $subscription->forceFill([
            'current_period_end' => now()->subDays(30),
            'grace_ends_at' => now()->subDays(23),
        ])->save();

        Sanctum::actingAs($this->tailor);

        $this->getJson("/api/orders/{$order->id}")->assertOk();
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(1, 'data');
    }
}
