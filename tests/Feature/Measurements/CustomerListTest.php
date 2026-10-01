<?php

namespace Tests\Feature\Measurements;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A tailor's list of the people she has sewn for.
 *
 * The narrow exception to "never a search": it must contain her own
 * customers and nobody else, because the rule it bends exists to stop a
 * tailor building a directory of other people's clients.
 */
class CustomerListTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tailor = User::factory()->tailor()->create();
    }

    private function orderFor(User $customer, string $status = Order::IN_PROGRESS, ?User $tailor = null): Order
    {
        return Order::factory()->create([
            'tailor_id' => ($tailor ?? $this->tailor)->id,
            'customer_id' => $customer->id,
            'status' => $status,
        ]);
    }

    public function test_she_sees_the_people_she_has_sewn_for(): void
    {
        $amaka = User::factory()->customer()->create(['name' => 'Amaka Obi']);
        $this->orderFor($amaka, Order::COMPLETED);
        $this->orderFor($amaka, Order::IN_PROGRESS);

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Amaka Obi')
            ->assertJsonPath('data.0.orders_count', 2)
            ->assertJsonPath('data.0.live_orders_count', 1);
    }

    /** The whole point of the rule this bends: nobody else's customers. */
    public function test_she_never_sees_another_tailors_customers_or_strangers(): void
    {
        $theirs = User::factory()->customer()->create();
        $this->orderFor($theirs, Order::IN_PROGRESS, User::factory()->tailor()->create());

        User::factory()->customer()->create(); // a stranger with no orders at all

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0, 'data');
    }

    /** Cancelled before anything happened: never sewn for. */
    public function test_a_cancelled_only_customer_is_not_listed(): void
    {
        $this->orderFor(User::factory()->customer()->create(), Order::CANCELLED);

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_most_recent_customer_comes_first(): void
    {
        $earlier = User::factory()->customer()->create(['name' => 'Earlier']);
        $later = User::factory()->customer()->create(['name' => 'Later']);

        $this->orderFor($earlier)->forceFill(['created_at' => now()->subWeek()])->save();
        $this->orderFor($later);

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers')
            ->assertJsonPath('data.0.name', 'Later')
            ->assertJsonPath('data.1.name', 'Earlier');
    }

    /**
     * The Measurements button must never lead to "not found". A live order
     * grants access; a finished one does not keep granting it.
     */
    public function test_it_says_whose_measurements_she_can_open(): void
    {
        $working = User::factory()->customer()->create(['name' => 'Working']);
        $finished = User::factory()->customer()->create(['name' => 'Finished']);

        $this->orderFor($working, Order::IN_PROGRESS);
        $this->orderFor($finished, Order::COMPLETED);

        Sanctum::actingAs($this->tailor);

        $rows = collect($this->getJson('/api/customers')->json('data'))->keyBy('name');

        $this->assertTrue($rows['Working']['can_see_measurements']);
        $this->assertFalse($rows['Finished']['can_see_measurements']);
    }

    public function test_it_comes_a_page_at_a_time(): void
    {
        foreach (range(1, 25) as $i) {
            $this->orderFor(User::factory()->customer()->create());
        }

        Sanctum::actingAs($this->tailor);

        $first = $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('meta.last_page', 2)
            ->json('data.*.id');

        $second = $this->getJson('/api/customers?page=2')
            ->assertJsonCount(5, 'data')
            ->json('data.*.id');

        // Nobody on both pages, nobody missing.
        $this->assertCount(25, array_unique([...$first, ...$second]));
    }

    public function test_she_searches_by_name_or_part_of_a_number(): void
    {
        $amaka = User::factory()->customer()->create(['name' => 'Amaka Obi', 'phone' => '08031112233']);
        $ngozi = User::factory()->customer()->create(['name' => 'Ngozi Eze', 'phone' => '08094445566']);
        $this->orderFor($amaka);
        $this->orderFor($ngozi);

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers?q=amaka')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Amaka Obi');
        // The last four digits, which is what people remember.
        $this->getJson('/api/customers?q=5566')->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Ngozi Eze');
        // However the number is written.
        $this->getJson('/api/customers?q='.urlencode('+234 803 111'))->assertJsonPath('data.0.name', 'Amaka Obi');
        $this->getJson('/api/customers?q=nobody')->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    }

    /** The search runs inside "has an order from her" -- it can never reach further. */
    public function test_a_search_never_reaches_another_tailors_customers(): void
    {
        $theirs = User::factory()->customer()->create(['name' => 'Amaka Theirs', 'phone' => '08031112233']);
        $this->orderFor($theirs, Order::IN_PROGRESS, User::factory()->tailor()->create());

        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers?q=Amaka')->assertJsonCount(0, 'data');
        $this->getJson('/api/customers?q=08031112233')->assertJsonCount(0, 'data');
    }

    public function test_only_a_tailor_has_a_customer_list(): void
    {
        foreach ([User::factory()->customer()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);
            $this->getJson('/api/customers')->assertForbidden();
        }
    }
}
