<?php

namespace Tests\Feature\Notifications;

use App\Models\Notification;
use App\Models\PushSubscription;
use App\Models\User;
use App\Notifications\Channels\AppDatabaseChannel;
use App\Notifications\Channels\WebPushChannel;
use App\Notifications\ClosetNotification;
use App\Support\NotificationCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * What actually reaches somebody, and what a preference can and cannot silence.
 *
 * The in-app record is the thing these protect: it is what happened to
 * somebody's cloth and money, and no toggle should be able to erase it.
 */
class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_notification_writes_the_in_app_record(): void
    {
        $user = User::factory()->create();

        $user->notify(new FakeStepNotification);

        $notification = Notification::query()->sole();

        $this->assertSame($user->id, $notification->user_id);
        $this->assertSame('step_completed', $notification->type);
        $this->assertSame('Your blouse is cut.', $notification->payload['message']);
        $this->assertSame('/orders/7', $notification->payload['url']);
        $this->assertNull($notification->read_at);
    }

    public function test_muting_a_group_silences_push_but_never_the_record(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [NotificationCategories::PROGRESS => false],
        ]);

        $channels = (new FakeStepNotification)->via($user);

        $this->assertContains(AppDatabaseChannel::class, $channels);
        $this->assertNotContains(WebPushChannel::class, $channels);

        // And the row is still written.
        $user->notify(new FakeStepNotification);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_an_account_that_has_chosen_nothing_hears_everything(): void
    {
        $user = User::factory()->create(['notification_preferences' => null]);

        $this->assertContains(WebPushChannel::class, (new FakeStepNotification)->via($user));
        $this->assertTrue($user->wantsPush('step_completed'));
    }

    public function test_a_group_added_later_is_not_silently_muted(): void
    {
        // Preferences saved before `measurements` existed as a group.
        $user = User::factory()->create([
            'notification_preferences' => [NotificationCategories::PROGRESS => false],
        ]);

        $this->assertFalse($user->wantsPush('step_completed'));
        $this->assertTrue($user->wantsPush('measurement_access_requested'));
    }

    public function test_account_notices_cannot_be_switched_off(): void
    {
        // Every optional group off, which is the most a person can do.
        $user = User::factory()->create([
            'notification_preferences' => array_fill_keys(
                array_keys(NotificationCategories::OPTIONAL),
                false,
            ),
        ]);

        $this->assertTrue($user->wantsPush('account_suspended'));

        // An unmapped type falls under ACCOUNT, so a notification somebody
        // forgot to categorise reaches people rather than going quietly missing.
        $this->assertTrue($user->wantsPush('something_nobody_mapped'));
    }

    public function test_mail_is_skipped_for_the_many_accounts_with_no_address(): void
    {
        Mail::fake();

        $user = User::factory()->create(['email' => null]);

        $this->assertNotContains('mail', (new FakeStepNotification)->via($user));

        $user->notify(new FakeStepNotification);

        Mail::assertNothingSent();
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_mail_goes_to_the_few_who_do_have_one(): void
    {
        $user = User::factory()->withEmail()->create();

        $this->assertContains('mail', (new FakeStepNotification)->via($user));
    }

    public function test_push_does_nothing_when_no_vapid_keys_are_set(): void
    {
        config(['services.push.public_key' => null, 'services.push.private_key' => null]);

        $user = User::factory()->create();
        $user->pushSubscriptions()->createQuietly(
            PushSubscription::factory()->make()->only([
                'endpoint', 'public_key', 'auth_token', 'device_label',
            ]),
        );

        // The point is that this does not throw. An environment with no keys
        // must still be able to run the platform; push is a courtesy on top.
        $user->notify(new FakeStepNotification);

        $this->assertSame(1, Notification::query()->count());
    }
}

/** Stands in for the step-tracker notification Section 9 will send. */
class FakeStepNotification extends ClosetNotification
{
    public function type(): string
    {
        return 'step_completed';
    }

    public function subject(object $notifiable): string
    {
        return 'Your order has moved on';
    }

    public function payload(object $notifiable): array
    {
        return ['message' => 'Your blouse is cut.'];
    }

    public function url(object $notifiable): ?string
    {
        return '/orders/7';
    }
}
