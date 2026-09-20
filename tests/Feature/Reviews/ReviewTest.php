<?php

namespace Tests\Feature\Reviews;

use App\Models\Order;
use App\Models\OrderStep;
use App\Models\OrderStepPhoto;
use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\TailorProfile;
use App\Models\User;
use App\Notifications\ReviewReceived;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two-way reviews and the proof gate.
 *
 * The gate exists for one failure: a tailor inventing orders with a
 * confederate and giving herself five stars. Every narrowing below is there
 * because the broader version would do harm -- most of all the rule that a
 * complaint always publishes.
 */
class ReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->customer = User::factory()->customer()->create();
        $this->tailor = User::factory()->tailor()->create();
        TailorProfile::factory()->create(['user_id' => $this->tailor->id]);

        PlatformSetting::query()->updateOrCreate(
            ['key' => PlatformSetting::REVIEW_PROOF_THRESHOLD], ['value' => '80'],
        );
        PlatformSetting::query()->updateOrCreate(
            ['key' => PlatformSetting::REVIEW_PROOF_MIN_STEPS], ['value' => '3'],
        );
    }

    /**
     * A finished order with `$steps` stages, `$photographed` of them shown.
     */
    private function finishedOrder(int $steps = 5, int $photographed = 5, string $status = Order::COLLECTED): Order
    {
        $order = Order::factory()->create([
            'customer_id' => $this->customer->id,
            'tailor_id' => $this->tailor->id,
        ]);

        for ($i = 1; $i <= $steps; $i++) {
            $step = OrderStep::create([
                'order_id' => $order->id,
                'position' => $i,
                'label' => "Stage {$i}",
            ]);
            $step->forceFill(['completed_at' => now()])->save();

            if ($i <= $photographed) {
                OrderStepPhoto::factory()->create([
                    'order_step_id' => $step->id,
                    'order_id' => $order->id,
                ]);
            }
        }

        $order->forceFill([
            'status' => $status,
            'collected_at' => now(),
            'steps_total' => $steps,
            'steps_completed' => $steps,
            'steps_with_photo' => $photographed,
        ])->save();

        return $order->fresh();
    }

    private function review(Order $order, User $as, int $rating, ?string $body = null)
    {
        Sanctum::actingAs($as);

        return $this->postJson("/api/orders/{$order->id}/reviews", [
            'rating' => $rating,
            'body' => $body,
        ]);
    }

    /* ===================================================================== */

    public function test_a_well_documented_order_publishes_five_stars(): void
    {
        $order = $this->finishedOrder(steps: 5, photographed: 5);

        $this->review($order, $this->customer, 5, 'Beautiful work.')
            ->assertCreated()
            ->assertJsonPath('data.status', Review::PUBLISHED);

        Notification::assertSentTo($this->tailor, ReviewReceived::class);
    }

    /** The failure the gate is for: five stars on an order that showed nothing. */
    public function test_five_stars_on_an_undocumented_order_is_held(): void
    {
        $order = $this->finishedOrder(steps: 5, photographed: 0);

        $this->review($order, $this->customer, 5)
            ->assertCreated()
            ->assertJsonPath('data.status', Review::HELD);

        $review = Review::query()->sole();

        $this->assertSame('0.00', $review->proof_ratio_snapshot);
        $this->assertNull($review->published_at);

        // Nothing is announced, because nothing is visible. Telling her about
        // a review she cannot read would also leak its rating.
        Notification::assertNothingSent();
    }

    /**
     * THE RULE THAT MATTERS MOST.
     *
     * A complaint always publishes. Holding a one-star review reads as
     * censorship, and a tailor who did no photo work is precisely the one
     * whose bad reviews most need to be seen.
     */
    public function test_a_complaint_always_publishes_however_little_was_shown(): void
    {
        foreach ([1, 2, 3] as $rating) {
            Review::query()->delete();
            $order = $this->finishedOrder(steps: 6, photographed: 0);

            $this->review($order, $this->customer, $rating)
                ->assertCreated()
                ->assertJsonPath('data.status', Review::PUBLISHED, "rating {$rating} must publish");
        }
    }

    public function test_the_threshold_is_the_boundary(): void
    {
        // 4 of 5 = 80%, exactly the threshold, which passes.
        $this->review($this->finishedOrder(steps: 5, photographed: 4), $this->customer, 5)
            ->assertJsonPath('data.status', Review::PUBLISHED);

        Review::query()->delete();

        // 3 of 5 = 60%, below it.
        $this->review($this->finishedOrder(steps: 5, photographed: 3), $this->customer, 5)
            ->assertJsonPath('data.status', Review::HELD);
    }

    /**
     * A short order's ratio is not information. Two stages with one photograph
     * is 50% and means nothing either way.
     */
    public function test_an_order_with_too_few_stages_is_never_held(): void
    {
        $this->review($this->finishedOrder(steps: 2, photographed: 0), $this->customer, 5)
            ->assertCreated()
            ->assertJsonPath('data.status', Review::PUBLISHED);
    }

    /** A tailor rating a customer cannot inflate what the directory ranks. */
    public function test_a_tailors_review_of_a_customer_is_never_gated(): void
    {
        $order = $this->finishedOrder(steps: 6, photographed: 0);

        $this->review($order, $this->tailor, 5)
            ->assertCreated()
            ->assertJsonPath('data.status', Review::PUBLISHED)
            ->assertJsonPath('data.direction', Review::TAILOR_TO_CUSTOMER);
    }

    /** The ratio is snapshotted even when it passes: it is why the decision went that way. */
    public function test_the_ratio_is_snapshotted_on_a_gated_review_that_passes(): void
    {
        $order = $this->finishedOrder(steps: 4, photographed: 4);

        $this->review($order, $this->customer, 4)->assertCreated();

        $this->assertSame('100.00', Review::query()->sole()->proof_ratio_snapshot);
    }

    /**
     * A tailor photographs a stage as she finishes the sleeve and ticks the
     * circle later, so mid-order there can be more photographed stages than
     * completed ones. The percentage must still be a percentage.
     */
    public function test_the_ratio_never_exceeds_one_hundred(): void
    {
        // Six photographed, four ticked: above the min-steps floor, so the
        // gate really runs, and the raw division would be 150%.
        $order = $this->finishedOrder(steps: 6, photographed: 6);
        $order->forceFill(['steps_completed' => 4])->save();

        $this->review($order->fresh(), $this->customer, 5)->assertCreated();

        $this->assertSame('100.00', Review::query()->sole()->proof_ratio_snapshot);
    }

    /* ===================================================================== */

    public function test_both_sides_can_review_the_same_order(): void
    {
        $order = $this->finishedOrder();

        $this->review($order, $this->customer, 5)->assertCreated();
        $this->review($order, $this->tailor, 4)->assertCreated();

        $this->assertSame(2, $order->reviews()->count());
    }

    public function test_nobody_reviews_the_same_order_twice(): void
    {
        $order = $this->finishedOrder();

        $this->review($order, $this->customer, 5)->assertCreated();
        $this->review($order, $this->customer, 1)->assertStatus(422);

        $this->assertSame(1, $order->reviews()->count());
    }

    public function test_an_unfinished_order_cannot_be_reviewed(): void
    {
        foreach ([Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY] as $status) {
            $order = $this->finishedOrder(status: $status);

            $this->review($order, $this->customer, 5)->assertStatus(422);
        }

        $this->assertSame(0, Review::query()->count());
    }

    public function test_a_stranger_cannot_review_an_order(): void
    {
        $order = $this->finishedOrder();

        $this->review($order, User::factory()->customer()->create(), 5)->assertNotFound();
    }

    public function test_a_rating_outside_one_to_five_is_refused(): void
    {
        $order = $this->finishedOrder();

        foreach ([0, 6, -1] as $rating) {
            $this->review($order, $this->customer, $rating)->assertStatus(422);
        }
    }

    /* ===================================================================== */

    /** A held review is not a fact about anybody yet. */
    public function test_a_held_review_does_not_move_the_average(): void
    {
        $this->review($this->finishedOrder(steps: 5, photographed: 0), $this->customer, 5)
            ->assertCreated();

        $this->assertSame('0.00', $this->tailor->tailorProfile->fresh()->avg_rating);
    }

    public function test_a_published_review_moves_the_average(): void
    {
        $this->review($this->finishedOrder(), $this->customer, 5)->assertCreated();

        $this->assertSame('5.00', $this->tailor->tailorProfile->fresh()->avg_rating);
    }

    /** Recomputed from scratch, so nothing can drift. */
    public function test_the_average_is_recomputed_not_adjusted(): void
    {
        $second = User::factory()->customer()->create();

        $this->review($this->finishedOrder(), $this->customer, 5)->assertCreated();

        $order = Order::factory()->create([
            'customer_id' => $second->id,
            'tailor_id' => $this->tailor->id,
        ]);
        $order->forceFill(['status' => Order::COLLECTED, 'steps_completed' => 0])->save();

        $this->review($order->fresh(), $second, 3)->assertCreated();

        $this->assertSame('4.00', $this->tailor->tailorProfile->fresh()->avg_rating);
    }

    /* ===================================================================== */

    public function test_the_author_sees_her_own_held_review_but_nobody_else_does(): void
    {
        $order = $this->finishedOrder(steps: 5, photographed: 0);

        $this->review($order, $this->customer, 5)->assertCreated();

        Sanctum::actingAs($this->customer);
        $this->getJson("/api/orders/{$order->id}/reviews")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        Sanctum::actingAs($this->tailor);
        $this->getJson("/api/orders/{$order->id}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_held_review_is_absent_from_the_public_list(): void
    {
        $this->review($this->finishedOrder(steps: 5, photographed: 0), $this->customer, 5);

        Sanctum::actingAs($this->tailor);

        $this->getJson("/api/users/{$this->tailor->id}/reviews")
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('summary.average', null);
    }

    /* ===================================================================== */

    public function test_an_admin_releases_a_held_review(): void
    {
        $order = $this->finishedOrder(steps: 5, photographed: 0);
        $this->review($order, $this->customer, 5)->assertCreated();

        $review = Review::query()->sole();
        $admin = User::factory()->admin()->create();

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/reviews')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.proof_ratio', '0.00')
            ->assertJsonPath('data.0.order.steps_with_photo', 0);

        $this->postJson("/api/admin/reviews/{$review->id}/release")->assertOk();

        $review = $review->fresh();

        $this->assertTrue($review->isPublished());
        $this->assertSame($admin->id, $review->approved_by);
        $this->assertNotNull($review->published_at);

        // Only now does it count, and only now is she told.
        $this->assertSame('5.00', $this->tailor->tailorProfile->fresh()->avg_rating);
        Notification::assertSentTo($this->tailor, ReviewReceived::class);
    }

    public function test_a_tailor_cannot_release_her_own_held_review(): void
    {
        $this->review($this->finishedOrder(steps: 5, photographed: 0), $this->customer, 5);

        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/admin/reviews/'.Review::query()->sole()->id.'/release')
            ->assertForbidden();
    }

    public function test_releasing_an_already_published_review_is_refused(): void
    {
        $this->review($this->finishedOrder(), $this->customer, 5)->assertCreated();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/reviews/'.Review::query()->sole()->id.'/release')
            ->assertStatus(422);
    }

    /* ===================================================================== */

    /** Finished work counts, whether or not anybody got round to reviewing. */
    public function test_orders_completed_counts_orders_not_reviews(): void
    {
        $order = $this->finishedOrder();

        Sanctum::actingAs($this->customer);
        $this->postJson("/api/orders/{$order->id}/confirm")->assertOk();

        $this->assertSame(1, $this->tailor->tailorProfile->fresh()->orders_completed);
        $this->assertSame(0, Review::query()->count());
    }
}
