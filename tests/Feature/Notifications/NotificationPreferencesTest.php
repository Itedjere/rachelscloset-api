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

    /**
     * Stage and ready alerts only ever go to the customer, so a tailor offered
     * "Work on your clothes" was holding a switch wired to nothing.
     */
    public function test_a_tailor_is_offered_only_what_a_tailor_receives(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $keys = collect($this->getJson('/api/notifications/preferences')->json('data.groups'))
            ->pluck('key')
            ->all();

        $this->assertSame([NotificationCategories::ORDERS, NotificationCategories::MONEY], $keys);
    }

    public function test_a_customer_is_not_told_about_a_listing_she_does_not_have(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $groups = collect($this->getJson('/api/notifications/preferences')->json('data.groups'));

        $this->assertSame(
            [NotificationCategories::PROGRESS, NotificationCategories::ORDERS, NotificationCategories::MONEY],
            $groups->pluck('key')->all(),
        );
        $this->assertStringNotContainsString('listing', $groups->pluck('hint')->implode(' '));
    }

    /** Nothing sends a measurement alert yet; offer it when something does. */
    public function test_nobody_is_offered_measurements_until_something_sends_one(): void
    {
        foreach ([User::ROLE_CUSTOMER, User::ROLE_TAILOR, User::ROLE_ADMIN] as $role) {
            $keys = array_column(NotificationCategories::offeredTo($role), 'key');

            $this->assertNotContains(NotificationCategories::MEASUREMENTS, $keys, $role);
        }
    }

    /** Everything an admin receives is an ACCOUNT alert, which cannot be muted. */
    public function test_an_admin_is_offered_no_switches(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/notifications/preferences')
            ->assertOk()
            ->assertJsonCount(0, 'data.groups');
    }

    /**
     * Hiding a switch must not reset it. The page sends every stored value
     * back on save, so a choice made before the switch was hidden survives
     * her next change to one she can see.
     */
    public function test_a_hidden_switch_keeps_its_value_through_a_save(): void
    {
        $tailor = User::factory()->tailor()->create([
            'notification_preferences' => [NotificationCategories::PROGRESS => false],
        ]);
        Sanctum::actingAs($tailor);

        $stored = $this->getJson('/api/notifications/preferences')
            ->assertJsonPath('data.preferences.'.NotificationCategories::PROGRESS, false)
            ->json('data.preferences');

        $stored[NotificationCategories::MONEY] = false;

        $this->putJson('/api/notifications/preferences', ['preferences' => $stored])->assertOk();

        $this->assertFalse($tailor->fresh()->pushPreferences()[NotificationCategories::PROGRESS]);
        $this->assertFalse($tailor->fresh()->pushPreferences()[NotificationCategories::MONEY]);
    }

    public function test_turning_a_group_off_sticks(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $preferences = array_fill_keys(NotificationCategories::OPTIONAL, true);
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

        $preferences = array_fill_keys(NotificationCategories::OPTIONAL, true);
        $preferences['not_a_group'] = false;

        $this->putJson('/api/notifications/preferences', ['preferences' => $preferences])->assertOk();

        $this->assertArrayNotHasKey('not_a_group', $user->fresh()->pushPreferences());
    }

    public function test_preferences_belong_to_the_signed_in_account_only(): void
    {
        $mine = User::factory()->create();
        $hers = User::factory()->create();

        Sanctum::actingAs($mine);

        $preferences = array_fill_keys(NotificationCategories::OPTIONAL, false);
        $this->putJson('/api/notifications/preferences', ['preferences' => $preferences])->assertOk();

        $this->assertNull($hers->fresh()->notification_preferences);
    }
}
