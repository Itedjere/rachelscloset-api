<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_list_is_newest_first_and_carries_the_unread_count(): void
    {
        $user = User::factory()->create();
        Notification::factory()->for($user)->read()->create(['created_at' => now()->subHour()]);
        $newest = Notification::factory()->for($user)->create(['created_at' => now()]);

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.id', $newest->id)
            ->assertJsonPath('unread_count', 1)
            ->assertJsonCount(2, 'data');
    }

    public function test_one_persons_notifications_are_not_another_persons(): void
    {
        $mine = User::factory()->create();
        $hers = User::factory()->create();
        Notification::factory()->for($hers)->create();

        Sanctum::actingAs($mine);

        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_someone_elses_notification_is_not_found_rather_than_forbidden(): void
    {
        $hers = Notification::factory()->create();

        Sanctum::actingAs(User::factory()->create());

        // 404, not 403: a 403 confirms the row exists, and ids are sequential.
        $this->postJson("/api/notifications/{$hers->id}/read")->assertNotFound();

        $this->assertNull($hers->fresh()->read_at);
    }

    public function test_marking_read_twice_keeps_the_first_time(): void
    {
        $user = User::factory()->create();
        $notification = Notification::factory()->for($user)->create();

        Sanctum::actingAs($user);

        $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();
        $first = $notification->fresh()->read_at;

        $this->travel(5)->minutes();

        $this->postJson("/api/notifications/{$notification->id}/read")->assertOk();

        $this->assertEquals($first, $notification->fresh()->read_at);
    }

    public function test_marking_all_read_leaves_other_people_alone(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(3)->for($user)->create();
        $hers = Notification::factory()->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);

        $this->assertSame(0, $user->appNotifications()->unread()->count());
        $this->assertNull($hers->fresh()->read_at);
    }

    public function test_the_unread_filter_and_the_badge_agree(): void
    {
        $user = User::factory()->create();
        Notification::factory()->count(2)->for($user)->create();
        Notification::factory()->for($user)->read()->create();

        Sanctum::actingAs($user);

        $this->getJson('/api/notifications?unread=1')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // The count is of everything unread, not of this page.
            ->assertJsonPath('unread_count', 2);

        $this->getJson('/api/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 2);
    }

    public function test_signed_out_gets_nothing(): void
    {
        $this->getJson('/api/notifications')->assertUnauthorized();
        $this->getJson('/api/notifications/unread-count')->assertUnauthorized();
    }
}
