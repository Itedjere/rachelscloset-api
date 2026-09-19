<?php

namespace Tests\Feature\Notifications;

use App\Models\User;
use App\Support\NotificationCategories;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_switches_arrive_with_their_labels(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonStructure(['data' => ['groups' => [['key', 'label', 'hint']], 'preferences']])
            // Everything on, for an account that has never opened this page.
            ->assertJsonPath('data.preferences.'.NotificationCategories::PROGRESS, true);
    }

    public function test_the_account_group_is_not_offered_as_a_switch(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $keys = collect($this->getJson('/api/notifications/preferences')->json('data.groups'))
            ->pluck('key');

        $this->assertNotContains(NotificationCategories::ACCOUNT, $keys);
    }

    public function test_turning_a_group_off_sticks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $preferences = array_fill_keys(array_keys(NotificationCategories::OPTIONAL), true);
        $preferences[NotificationCategories::MONEY] = false;

        $this->putJson('/api/notifications/preferences', ['preferences' => $preferences])
            ->assertOk()
            ->assertJsonPath('data.preferences.'.NotificationCategories::MONEY, false);

        $this->assertFalse($user->fresh()->wantsPush('payout_released'));
        $this->assertTrue($user->fresh()->wantsPush('step_completed'));
    }

    public function test_a_partial_update_is_rejected_rather_than_half_applied(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->putJson('/api/notifications/preferences', [
            'preferences' => [NotificationCategories::MONEY => false],
        ])->assertJsonValidationErrors('preferences.'.NotificationCategories::PROGRESS);
    }

    public function test_an_unknown_group_is_ignored_rather_than_stored(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $preferences = array_fill_keys(array_keys(NotificationCategories::OPTIONAL), true);
        $preferences['not_a_group'] = false;

        $this->putJson('/api/notifications/preferences', ['preferences' => $preferences])->assertOk();

        $this->assertArrayNotHasKey('not_a_group', $user->fresh()->pushPreferences());
    }

    public function test_preferences_belong_to_the_signed_in_account_only(): void
    {
        $mine = User::factory()->create();
        $hers = User::factory()->create();

        Sanctum::actingAs($mine);

        $preferences = array_fill_keys(array_keys(NotificationCategories::OPTIONAL), false);
        $this->putJson('/api/notifications/preferences', ['preferences' => $preferences])->assertOk();

        $this->assertNull($hers->fresh()->notification_preferences);
    }
}
