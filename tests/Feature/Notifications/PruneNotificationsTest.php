<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\PlatformSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_goes_sooner_than_unread(): void
    {
        // Twenty days old. Read is done with; unread never landed.
        $read = Notification::factory()->read()->aged(20)->create();
        $unread = Notification::factory()->aged(20)->create();

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $read->id]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id]);
    }

    public function test_recent_notifications_are_left_alone(): void
    {
        $read = Notification::factory()->read()->aged(3)->create();
        $unread = Notification::factory()->aged(3)->create();

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(2, Notification::query()->count());
        $this->assertDatabaseHas('notifications', ['id' => $read->id]);
        $this->assertDatabaseHas('notifications', ['id' => $unread->id]);
    }

    public function test_an_unread_notification_is_given_up_on_eventually(): void
    {
        Notification::factory()->aged(40)->create();

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertSame(0, Notification::query()->count());
    }

    public function test_the_windows_are_settings_not_constants(): void
    {
        PlatformSetting::set(PlatformSetting::NOTIFICATION_READ_RETENTION_DAYS, 60);
        $read = Notification::factory()->read()->aged(20)->create();

        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['id' => $read->id]);
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        Notification::factory()->read()->aged(20)->create();

        $this->artisan('notifications:prune --dry-run')->assertSuccessful();

        $this->assertSame(1, Notification::query()->count());
        // And it does not claim to have run, either.
        $this->assertNull(PlatformSetting::get(PlatformSetting::NOTIFICATIONS_PRUNED_AT));
    }

    /**
     * The stamp is the only way a cron that has quietly stopped becomes
     * visible before the disk fills up. See CLAUDE.md section 5.
     */
    public function test_every_real_run_stamps_when_it_happened(): void
    {
        $this->artisan('notifications:prune')->assertSuccessful();

        $this->assertNotNull(PlatformSetting::get(PlatformSetting::NOTIFICATIONS_PRUNED_AT));
    }
}
