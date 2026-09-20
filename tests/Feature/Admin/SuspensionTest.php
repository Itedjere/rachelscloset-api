<?php

namespace Tests\Feature\Admin;

use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\AccountReinstated;
use App\Notifications\AccountSuspended;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Suspending somebody.
 *
 * The enforcement has existed since Section 1 -- EnsureUserIsActive cuts a
 * live session rather than waiting for a sign-in, and a fixed term lapses on
 * use rather than on a schedule. Nothing could ever start one. This is the
 * missing half.
 */
class SuspensionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->admin()->create();
        $this->tailor = User::factory()->tailor()->create();

        PlatformSetting::set(PlatformSetting::DEFAULT_SUSPENSION_DAYS, '14');
    }

    /* ===================================================================== */

    public function test_an_admin_suspends_somebody_for_a_fixed_term(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend", [
            'reason' => 'Repeatedly did not start work that was paid for.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', User::STATUS_SUSPENDED);

        $tailor = $this->tailor->fresh();

        $this->assertFalse($tailor->isActive());
        // Fixed term, because an indefinite suspension is one nobody gets
        // round to lifting.
        $this->assertNotNull($tailor->suspended_until);
        $this->assertSame(14, (int) round(now()->diffInDays($tailor->suspended_until)));

        Notification::assertSentTo($this->tailor, AccountSuspended::class);
    }

    public function test_the_length_can_be_given(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend", ['days' => 3])->assertOk();

        $this->assertSame(3, (int) round(now()->diffInDays($this->tailor->fresh()->suspended_until)));
    }

    /** Locking every admin out by accident is not a button we need. */
    public function test_an_admin_cannot_suspend_herself(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->admin->id}/suspend")->assertStatus(422);

        $this->assertTrue($this->admin->fresh()->isActive());
    }

    /** A fight between colleagues is not something a button should settle. */
    public function test_an_admin_cannot_suspend_another_admin(): void
    {
        $other = User::factory()->admin()->create();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$other->id}/suspend")->assertStatus(422);
    }

    public function test_nobody_else_can_suspend_anybody(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend")->assertForbidden();
    }

    /* ===================================================================== */

    /** The middleware cuts a live session rather than waiting for a sign-in. */
    public function test_a_suspension_bites_immediately(): void
    {
        Sanctum::actingAs($this->tailor);
        $this->getJson('/api/me')->assertOk();

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend")->assertOk();

        Sanctum::actingAs($this->tailor->fresh());
        $this->getJson('/api/me')->assertForbidden();
    }

    public function test_reinstating_lets_her_back_in(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend")->assertOk();
        $this->postJson("/api/admin/users/{$this->tailor->id}/reinstate")->assertOk();

        $tailor = $this->tailor->fresh();

        $this->assertTrue($tailor->isActive());
        $this->assertNull($tailor->suspended_until);

        Notification::assertSentTo($this->tailor, AccountReinstated::class);

        Sanctum::actingAs($tailor);
        $this->getJson('/api/me')->assertOk();
    }

    public function test_reinstating_somebody_who_is_not_suspended_is_refused(): void
    {
        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$this->tailor->id}/reinstate")->assertStatus(422);
    }

    /* ===================================================================== */

    public function test_people_can_be_found_by_name_or_exact_number(): void
    {
        $found = User::factory()->customer()->create([
            'name' => 'Amaka Eze',
            'phone' => '08031112233',
        ]);
        User::factory()->customer()->create(['name' => 'Somebody Else']);

        Sanctum::actingAs($this->admin);

        $this->getJson('/api/admin/users?q=Amaka')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $found->id);

        // A number written any of the usual ways finds the canonical row.
        foreach (['08031112233', '+2348031112233'] as $written) {
            $this->getJson('/api/admin/users?q='.urlencode($written))
                ->assertOk()
                ->assertJsonPath('data.0.id', $found->id);
        }
    }

    public function test_people_can_be_filtered_by_role_and_status(): void
    {
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/users/{$this->tailor->id}/suspend")->assertOk();

        $this->getJson('/api/admin/users?role=tailor')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson('/api/admin/users?status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->tailor->id);
    }

    /** No measurements, no email, no PIN: one power and nothing else. */
    public function test_the_listing_carries_nothing_sensitive(): void
    {
        Sanctum::actingAs($this->admin);

        $row = $this->getJson('/api/admin/users')->assertOk()->json('data.0');

        $this->assertArrayNotHasKey('password', $row);
        $this->assertArrayNotHasKey('email', $row);
        $this->assertArrayNotHasKey('measurements', $row);
    }

    public function test_only_an_admin_may_list_people(): void
    {
        Sanctum::actingAs(User::factory()->tailor()->create());

        $this->getJson('/api/admin/users')->assertForbidden();
    }
}
