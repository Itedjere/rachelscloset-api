<?php

namespace Tests\Feature\Payments;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function pair(): array
    {
        return [User::factory()->tailor()->create(), User::factory()->customer()->create()];
    }

    public function test_a_tailor_opens_an_order(): void
    {
        [$tailor, $customer] = $this->pair();
        $garment = GarmentType::factory()->create();

        Sanctum::actingAs($tailor);

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'garment_type_id' => $garment->id,
            'amount' => 25000,
            'description' => 'Blue lace, long sleeve',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Order::PENDING_PAYMENT)
            ->assertJsonPath('data.amount', '25000.00');
    }

    /**
     * The tailor opens the order, not the customer.
     *
     * They are standing in the shop together when measurements are taken; she
     * is the one who knows what is being made and what it costs.
     */
    public function test_a_customer_cannot_open_an_order(): void
    {
        [$tailor, $customer] = $this->pair();
        $garment = GarmentType::factory()->create();

        Sanctum::actingAs($customer);

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'garment_type_id' => $garment->id,
            'amount' => 25000,
        ])->assertForbidden();
    }

    public function test_a_reference_is_speakable_and_unique(): void
    {
        $a = Order::factory()->create();
        $b = Order::factory()->create();

        $this->assertNotSame($a->reference, $b->reference);
        // Read down a phone line, so nothing that sounds like something else.
        $this->assertDoesNotMatchRegularExpression('/[01OI]/', substr($a->reference, 3));
    }

    /** A request body must never be able to move an order through its states. */
    public function test_status_cannot_be_set_when_opening_an_order(): void
    {
        [$tailor, $customer] = $this->pair();
        $garment = GarmentType::factory()->create();

        Sanctum::actingAs($tailor);

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'garment_type_id' => $garment->id,
            'amount' => 25000,
            'status' => Order::COMPLETED,
            'collected_at' => now()->toDateTimeString(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', Order::PENDING_PAYMENT);
    }

    public function test_a_retired_garment_cannot_take_new_orders(): void
    {
        [$tailor, $customer] = $this->pair();
        $garment = GarmentType::factory()->retired()->create();

        Sanctum::actingAs($tailor);

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'garment_type_id' => $garment->id,
            'amount' => 25000,
        ])->assertJsonValidationErrors('garment_type_id');
    }

    public function test_a_deposit_cannot_exceed_the_price(): void
    {
        [$tailor, $customer] = $this->pair();
        $garment = GarmentType::factory()->create();

        Sanctum::actingAs($tailor);

        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'garment_type_id' => $garment->id,
            'amount' => 10000,
            'deposit_amount' => 15000,
        ])->assertJsonValidationErrors('deposit_amount');
    }

    public function test_only_the_two_people_on_an_order_can_see_it(): void
    {
        $order = Order::factory()->create();

        foreach ([$order->customer, $order->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->getJson("/api/orders/{$order->id}")->assertOk();
        }

        // 404, not 403: whether an order exists is not confirmed to a stranger.
        Sanctum::actingAs(User::factory()->tailor()->create());
        $this->getJson("/api/orders/{$order->id}")->assertNotFound();
    }

    public function test_the_list_only_shows_your_own_orders(): void
    {
        $mine = Order::factory()->create();
        Order::factory()->count(3)->create();

        Sanctum::actingAs($mine->tailor);

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $mine->reference);
    }

    /** The state the brief was missing. */
    public function test_marking_ready_starts_a_collection_deadline(): void
    {
        PlatformSetting::set(PlatformSetting::COLLECTION_DEADLINE_DAYS, 10);

        $order = Order::factory()->inProgress()->create();
        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/ready")
            ->assertOk()
            ->assertJsonPath('data.status', Order::READY)
            ->assertJsonPath('data.collection_deadline', now()->addDays(10)->toDateString());
    }

    public function test_only_the_tailor_marks_an_order_ready(): void
    {
        $order = Order::factory()->inProgress()->create();

        Sanctum::actingAs($order->customer);
        $this->postJson("/api/orders/{$order->id}/ready")->assertNotFound();
    }

    public function test_an_order_cannot_skip_from_pending_to_ready(): void
    {
        $order = Order::factory()->create();
        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/ready")->assertStatus(422);
    }

    public function test_collecting_requires_being_ready_first(): void
    {
        $order = Order::factory()->inProgress()->create();
        Sanctum::actingAs($order->tailor);

        $this->postJson("/api/orders/{$order->id}/collected")->assertStatus(422);

        $this->postJson("/api/orders/{$order->id}/ready")->assertOk();
        $this->postJson("/api/orders/{$order->id}/collected")
            ->assertOk()
            ->assertJsonPath('data.status', Order::COLLECTED);
    }

    /**
     * Once work has started there is cloth cut and money held. Unwinding that
     * is a refund, not a cancellation.
     */
    public function test_an_order_in_progress_cannot_be_cancelled(): void
    {
        $order = Order::factory()->inProgress()->create();
        Sanctum::actingAs($order->customer);

        $this->postJson("/api/orders/{$order->id}/cancel")->assertStatus(422);
    }

    public function test_either_side_can_cancel_before_payment(): void
    {
        $order = Order::factory()->create();
        Sanctum::actingAs($order->customer);

        $this->postJson("/api/orders/{$order->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', Order::CANCELLED);
    }
}
