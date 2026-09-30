<?php

namespace Tests\Feature\Measurements;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A tailor adding the customer in front of her, when the lookup finds nobody.
 *
 * The half of the claim flow that was missing: claiming worked, but nothing
 * ever created a profile to claim.
 */
class AddCustomerTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tailor = User::factory()->tailor()->create();
    }

    public function test_a_tailor_adds_a_customer_with_no_pin(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/customers', ['name' => 'Amaka Obi', 'phone' => '08031112233'])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Amaka Obi')
            ->assertJsonPath('data.phone', '08031112233')
            ->assertJsonPath('data.claimed', false);

        $customer = User::query()->where('phone', '08031112233')->sole();

        $this->assertTrue($customer->isCustomer());
        $this->assertNull($customer->password);
        $this->assertNull($customer->email);
    }

    /** Normalised before the unique check, or one number becomes two people. */
    public function test_a_number_written_another_way_is_the_same_customer(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/customers', ['name' => 'Amaka Obi', 'phone' => '+234 803 111 2233'])
            ->assertCreated()
            ->assertJsonPath('data.phone', '08031112233');

        $this->postJson('/api/customers', ['name' => 'Amaka', 'phone' => '0803-111-2233'])->assertOk();

        $this->assertSame(1, User::query()->where('phone', '08031112233')->count());
    }

    /**
     * A double tap, or a second tailor meeting the same walk-in, gets the
     * existing customer back -- and does not rename her.
     */
    public function test_an_existing_customer_is_returned_not_renamed(): void
    {
        $existing = User::factory()->customer()->create(['name' => 'Ngozi Eze', 'phone' => '08031112233']);

        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/customers', ['name' => 'Ngozi E', 'phone' => '08031112233'])
            ->assertOk()
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.name', 'Ngozi Eze')
            ->assertJsonPath('data.claimed', true);

        $this->assertSame('Ngozi Eze', $existing->fresh()->name);
    }

    /** An order addressed to somebody's staff account is an order to the wrong person. */
    public function test_a_number_held_by_a_tailor_is_refused(): void
    {
        User::factory()->tailor()->create(['phone' => '08031112233']);

        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/customers', ['name' => 'Somebody', 'phone' => '08031112233'])
            ->assertJsonValidationErrors('phone');

        $this->assertSame(1, User::query()->where('phone', '08031112233')->count());
    }

    public function test_only_a_tailor_may_add_one(): void
    {
        foreach ([User::factory()->customer()->create(), User::factory()->admin()->create()] as $user) {
            Sanctum::actingAs($user);

            $this->postJson('/api/customers', ['name' => 'Amaka Obi', 'phone' => '08031112233'])
                ->assertForbidden();
        }

        $this->assertFalse(User::query()->where('phone', '08031112233')->exists());
    }

    public function test_a_name_and_a_real_number_are_both_needed(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->postJson('/api/customers', ['name' => '', 'phone' => '12345'])
            ->assertJsonValidationErrors(['name', 'phone']);
    }

    /** Added and unclaimed means no way in until she sets her own PIN. */
    public function test_an_added_customer_cannot_sign_in_until_she_claims(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->postJson('/api/customers', ['name' => 'Amaka Obi', 'phone' => '08031112233'])->assertCreated();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['identifier' => '08031112233', 'pin' => '000000'])
            ->assertJsonValidationErrors('identifier');
    }

    /**
     * She cannot pay or follow the tracker until she claims, so the order
     * says so and the tailor's order page can put the invite in front of her.
     */
    public function test_an_order_says_whether_its_customer_has_claimed(): void
    {
        $walkIn = User::factory()->customer()->unclaimed()->create();
        $order = Order::factory()->create(['tailor_id' => $this->tailor->id, 'customer_id' => $walkIn->id]);

        Sanctum::actingAs($this->tailor);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.customer.claimed', false)
            ->assertJsonPath('data.tailor.claimed', true);
    }

    /**
     * The whole shop-floor journey: not found, added, invited, claimed,
     * signed in. This is the path that did not exist.
     */
    public function test_added_invited_claimed_and_signed_in(): void
    {
        Sanctum::actingAs($this->tailor);

        $this->getJson('/api/customers/lookup?phone=08031112233')->assertNotFound();

        $id = $this->postJson('/api/customers', ['name' => 'Amaka Obi', 'phone' => '08031112233'])
            ->assertCreated()
            ->json('data.id');

        $this->getJson('/api/customers/lookup?phone=08031112233')->assertOk()->assertJsonPath('data.id', $id);

        $code = $this->postJson("/api/customers/{$id}/claim-invite")->assertOk()->json('data.code');

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/claim', [
            'code' => $code,
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/login', ['identifier' => '08031112233', 'pin' => '493028'])
            ->assertOk()
            ->assertJsonPath('user.id', $id);
    }
}
