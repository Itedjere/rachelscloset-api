<?php

namespace Tests\Feature\Auth;

use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amaka Eze',
            'phone' => '08031234567',
            'pin' => '482917',
            'pin_confirmation' => '482917',
            'role' => 'customer',
        ], $overrides);
    }

    public function test_a_customer_can_register_without_an_email_address(): void
    {
        // The case the whole platform is shaped around.
        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone', 'role']])
            ->assertJsonPath('user.email', null);

        $this->assertDatabaseHas('users', ['phone' => '08031234567', 'email' => null]);
    }

    public function test_a_tailor_gets_a_profile_and_a_slug(): void
    {
        $this->postJson('/api/register', $this->payload([
            'role' => 'tailor',
            'business_name' => 'Mama Ngozi Couture',
            'location' => 'Rumuodara',
            'state' => 'Rivers',
        ]))->assertCreated();

        $profile = TailorProfile::firstOrFail();
        $this->assertSame('mama-ngozi-couture', $profile->slug);
    }

    public function test_two_tailors_with_the_same_shop_name_get_different_addresses(): void
    {
        foreach (['08031234567', '08031234568'] as $phone) {
            $this->postJson('/api/register', $this->payload([
                'phone' => $phone,
                'role' => 'tailor',
                'business_name' => 'Golden Needle',
                'location' => 'Ikeja',
            ]))->assertCreated();
        }

        $this->assertSame(
            ['golden-needle', 'golden-needle-2'],
            TailorProfile::orderBy('id')->pluck('slug')->all(),
        );
    }

    public function test_a_tailor_must_name_her_shop(): void
    {
        $this->postJson('/api/register', $this->payload(['role' => 'tailor']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['business_name', 'location']);
    }

    public function test_the_phone_number_is_stored_one_way_however_it_is_typed(): void
    {
        $this->postJson('/api/register', $this->payload(['phone' => '+234 803 123 4567']))
            ->assertCreated();

        $this->assertDatabaseHas('users', ['phone' => '08031234567']);
    }

    public function test_the_same_number_written_differently_cannot_become_two_accounts(): void
    {
        // Normalisation has to happen BEFORE the unique rule runs, or this passes.
        $this->postJson('/api/register', $this->payload())->assertCreated();

        $this->postJson('/api/register', $this->payload(['phone' => '+2348031234567']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_pin_must_be_six_digits(): void
    {
        foreach (['4829', '48291', '4829170', 'abcdef', '48 29 17'] as $bad) {
            $this->postJson('/api/register', $this->payload(['pin' => $bad, 'pin_confirmation' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('pin');
        }
    }

    public function test_guessable_pins_are_refused(): void
    {
        foreach (['111111', '000000', '123456', '654321'] as $bad) {
            $this->postJson('/api/register', $this->payload(['pin' => $bad, 'pin_confirmation' => $bad]))
                ->assertStatus(422)
                ->assertJsonValidationErrors('pin');
        }
    }

    public function test_a_pin_taken_from_your_own_phone_number_is_refused(): void
    {
        // 08031234567 contains 312345 — and the number is on the screen beside it.
        $this->postJson('/api/register', $this->payload(['pin' => '312345', 'pin_confirmation' => '312345']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_the_pin_must_be_confirmed(): void
    {
        $this->postJson('/api/register', $this->payload(['pin_confirmation' => '482918']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('pin');
    }

    public function test_nobody_can_sign_themselves_up_as_an_admin(): void
    {
        $this->postJson('/api/register', $this->payload(['role' => 'admin']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');
    }

    public function test_a_new_account_is_active_on_the_object_it_returns(): void
    {
        // The schema default fills the row, not the model that was just made.
        // Without an explicit attribute default, isActive() was false on the
        // very instance handed back from registration.
        $this->postJson('/api/register', $this->payload())
            ->assertCreated()
            ->assertJsonPath('user.status', 'active');

        $this->assertTrue(User::firstOrFail()->isActive());
    }

    public function test_the_pin_is_hashed_not_stored(): void
    {
        $this->postJson('/api/register', $this->payload())->assertCreated();

        $this->assertNotSame('482917', User::firstOrFail()->password);
    }
}
