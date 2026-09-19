<?php

namespace Tests\Feature\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminOrderTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * A deliberate widening of the ordinary scoping.
     *
     * An admin is on none of these orders, and cannot decide a refund without
     * seeing what was paid.
     */
    public function test_an_admin_sees_every_order(): void
    {
        Order::factory()->count(3)->create();
        $this->asAdmin();

        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(3, 'data');
    }

    public function test_the_ordinary_list_is_still_scoped(): void
    {
        Order::factory()->count(3)->create();

        // An admin using the normal endpoint sees their own, which is none.
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/orders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_nobody_else_reaches_the_admin_list(): void
    {
        $order = Order::factory()->create();

        foreach ([$order->customer, $order->tailor] as $party) {
            Sanctum::actingAs($party);
            $this->getJson('/api/admin/orders')->assertForbidden();
            $this->getJson("/api/admin/orders/{$order->id}")->assertForbidden();
        }
    }

    public function test_searching_by_reference(): void
    {
        $wanted = Order::factory()->create();
        Order::factory()->count(2)->create();

        $this->asAdmin();

        $this->getJson('/api/admin/orders?q='.$wanted->reference)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.reference', $wanted->reference);
    }

    public function test_searching_by_phone_finds_either_side(): void
    {
        $order = Order::factory()->create();
        Order::factory()->count(2)->create();

        $this->asAdmin();

        foreach ([$order->customer->phone, $order->tailor->phone] as $phone) {
            $this->getJson('/api/admin/orders?q='.$phone)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.reference', $order->reference);
        }
    }

    /** A reference search must not also sweep the user table. */
    public function test_a_search_that_is_not_a_phone_stays_a_reference_search(): void
    {
        Order::factory()->count(3)->create();
        $this->asAdmin();

        $this->getJson('/api/admin/orders?q=definitely-not-a-reference')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_filtering_by_status(): void
    {
        Order::factory()->create();
        Order::factory()->inProgress()->create();
        $this->asAdmin();

        $this->getJson('/api/admin/orders?status='.Order::IN_PROGRESS)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', Order::IN_PROGRESS);
    }

    /** What actually arrived, which is what a refund decision turns on. */
    public function test_the_detail_shows_the_payments(): void
    {
        $order = Order::factory()->escrow()->create(['amount' => '25000.00']);

        Payment::factory()->successful()->create([
            'order_id' => $order->id,
            'payer_id' => $order->customer_id,
            'amount' => '10000.00',
        ]);

        $this->asAdmin();

        $this->getJson("/api/admin/orders/{$order->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.payments')
            ->assertJsonPath('data.payments.0.amount', '10000.00')
            ->assertJsonPath('data.paid_total', '10000.00');
    }
}
