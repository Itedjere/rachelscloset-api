<?php

namespace Tests\Feature\Auth;

use App\Models\PlatformSetting;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Getting the first admin onto a live server, and keeping the demo one off it.
 */
class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_admin_with_a_hashed_pin_and_a_normalised_phone(): void
    {
        $this->artisan('admin:create')
            ->expectsQuestion('Their name', 'Rachel Okafor')
            ->expectsQuestion('Their phone number (this is how they sign in)', '+234 803 555 1234')
            ->expectsQuestion('Choose a 6-digit PIN (hidden)', '730194')
            ->expectsQuestion('Type the PIN again', '730194')
            ->assertSuccessful();

        $admin = User::where('phone', '08035551234')->firstOrFail();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue(Hash::check('730194', $admin->password));
        $this->assertNotSame('730194', $admin->password);
    }

    public function test_the_name_and_phone_can_be_options_but_the_pin_is_always_asked(): void
    {
        $this->artisan('admin:create', ['--name' => 'Rachel', '--phone' => '08035551234'])
            ->expectsQuestion('Choose a 6-digit PIN (hidden)', '730194')
            ->expectsQuestion('Type the PIN again', '730194')
            ->assertSuccessful();

        $this->assertTrue(User::where('phone', '08035551234')->firstOrFail()->isAdmin());
    }

    public function test_a_guessable_pin_is_refused_and_nothing_is_created(): void
    {
        $this->artisan('admin:create', ['--name' => 'Rachel', '--phone' => '08035551234'])
            ->expectsQuestion('Choose a 6-digit PIN (hidden)', '123456')
            ->expectsQuestion('Type the PIN again', '123456')
            ->expectsOutputToContain('too easy to guess')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['phone' => '08035551234']);
    }

    public function test_two_different_pins_are_refused(): void
    {
        $this->artisan('admin:create', ['--name' => 'Rachel', '--phone' => '08035551234'])
            ->expectsQuestion('Choose a 6-digit PIN (hidden)', '730194')
            ->expectsQuestion('Type the PIN again', '730195')
            ->expectsOutputToContain('not the same')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['phone' => '08035551234']);
    }

    /** An existing account is not quietly turned into an admin. */
    public function test_a_number_that_already_has_an_account_is_refused(): void
    {
        $customer = User::factory()->customer()->create(['phone' => '08035551234']);

        $this->artisan('admin:create', ['--name' => 'Rachel', '--phone' => '0803 555 1234'])
            ->expectsQuestion('Choose a 6-digit PIN (hidden)', '730194')
            ->expectsQuestion('Type the PIN again', '730194')
            ->expectsOutputToContain('already has an account')
            ->assertFailed();

        $this->assertFalse($customer->fresh()->isAdmin());
    }

    /** Never from a script or a pipe: the PIN must be typed, hidden. */
    public function test_it_refuses_to_run_without_a_terminal(): void
    {
        $this->artisan('admin:create', ['--no-interaction' => true])
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    /**
     * The seeder outside `local` makes the settings and the stage library and
     * stops -- no demo admin with a PIN that is written in CLAUDE.md.
     */
    public function test_the_seeder_makes_no_demo_accounts_outside_local(): void
    {
        $this->assertFalse($this->app->environment('local'));

        $this->seed(DatabaseSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertNotNull(PlatformSetting::get(PlatformSetting::SUPPORT_PHONE));
    }
}
