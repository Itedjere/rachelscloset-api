<?php

namespace Tests\Feature\Notifications;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123'): array
    {
        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => str_repeat('k', 87), 'auth' => str_repeat('a', 22)],
        ];
    }

    public function test_a_browser_can_subscribe(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
        ]);
    }

    public function test_subscribing_twice_from_one_browser_does_not_make_two_rows(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated();
        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
    }

    /**
     * The case this table's unique index exists for. One phone between several
     * people is ordinary here, and the second person must not start receiving
     * the first person's order alerts.
     */
    public function test_a_shared_phone_moves_to_whoever_signed_in_last(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($first);
        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated();

        Sanctum::actingAs($second);
        $this->postJson('/api/push/subscriptions', $this->payload())->assertCreated();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame(0, $first->pushSubscriptions()->count());
        $this->assertSame(1, $second->pushSubscriptions()->count());
    }

    public function test_unhooking_one_device_leaves_the_others(): void
    {
        $user = User::factory()->create();
        $shop = PushSubscription::factory()->for($user)->create();
        $home = PushSubscription::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/push/subscriptions', ['id' => $shop->id])
            ->assertOk()
            ->assertJsonPath('data.removed', 1);

        $this->assertDatabaseMissing('push_subscriptions', ['id' => $shop->id]);
        $this->assertDatabaseHas('push_subscriptions', ['id' => $home->id]);
    }

    public function test_unhooking_with_nothing_named_stops_alerts_everywhere(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->count(3)->for($user)->create();
        $hers = PushSubscription::factory()->create();

        Sanctum::actingAs($user);

        $this->deleteJson('/api/push/subscriptions')
            ->assertOk()
            ->assertJsonPath('data.removed', 3);

        // Scoped to this user, so "everywhere" never means everybody.
        $this->assertDatabaseHas('push_subscriptions', ['id' => $hers->id]);
    }

    public function test_one_person_cannot_unhook_another_persons_device(): void
    {
        $hers = PushSubscription::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        $this->deleteJson('/api/push/subscriptions', ['id' => $hers->id])
            ->assertOk()
            ->assertJsonPath('data.removed', 0);

        $this->assertDatabaseHas('push_subscriptions', ['id' => $hers->id]);
    }

    public function test_devices_are_listed_only_to_their_owner(): void
    {
        $user = User::factory()->create();
        PushSubscription::factory()->for($user)->create(['device_label' => 'Android · Chrome']);
        PushSubscription::factory()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/push/subscriptions')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.label', 'Android · Chrome');
    }

    public function test_the_app_is_told_push_is_off_rather_than_offering_a_dead_button(): void
    {
        config(['services.push.public_key' => null, 'services.push.private_key' => null]);

        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('data.push.enabled', false);
    }

    public function test_the_public_key_is_readable_without_signing_in(): void
    {
        config([
            'services.push.public_key' => 'a-public-key',
            'services.push.private_key' => 'a-private-key',
        ]);

        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('data.push.enabled', true)
            ->assertJsonPath('data.push.public_key', 'a-public-key');
    }

    public function test_a_device_label_tells_a_phone_from_a_laptop(): void
    {
        $this->assertSame('Android · Chrome', PushSubscription::labelFor(
            'Mozilla/5.0 (Linux; Android 10) AppleWebKit/537.36 Chrome/120.0 Mobile Safari/537.36',
        ));

        // Edge claims to be Chrome, so the order of those arms matters.
        $this->assertSame('Windows · Edge', PushSubscription::labelFor(
            'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/120.0 Safari/537.36 Edg/120.0',
        ));

        $this->assertNull(PushSubscription::labelFor(null));
    }
}
