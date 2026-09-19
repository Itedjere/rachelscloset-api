<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_signing_in_with_a_phone_number(): void
    {
        $user = User::factory()->create(['phone' => '08031234567']);

        $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => UserFactory::PIN])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.id', $user->id);
    }

    public function test_the_number_can_be_typed_any_of_the_usual_ways(): void
    {
        User::factory()->create(['phone' => '08031234567']);

        foreach (['08031234567', '+2348031234567', '234 803 123 4567', '0803-123-4567'] as $typed) {
            $this->postJson('/api/login', ['identifier' => $typed, 'pin' => UserFactory::PIN])
                ->assertOk();
        }
    }

    public function test_signing_in_with_an_email_address_for_the_few_who_have_one(): void
    {
        User::factory()->withEmail()->create(['email' => 'ada@example.test']);

        $this->postJson('/api/login', ['identifier' => 'ada@example.test', 'pin' => UserFactory::PIN])
            ->assertOk();
    }

    public function test_a_wrong_pin_is_refused(): void
    {
        User::factory()->create(['phone' => '08031234567']);

        $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => '999999'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');
    }

    public function test_an_unknown_number_and_a_wrong_pin_are_indistinguishable(): void
    {
        // Otherwise the login form is a way of discovering whose numbers are
        // registered here, and a phone number is trivially guessable.
        User::factory()->create(['phone' => '08031234567']);

        $wrongPin = $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => '999999']);
        $noAccount = $this->postJson('/api/login', ['identifier' => '08039999999', 'pin' => '999999']);

        $this->assertSame($wrongPin->json('message'), $noAccount->json('message'));
        $this->assertSame($wrongPin->json('errors'), $noAccount->json('errors'));
    }

    public function test_an_unclaimed_profile_cannot_be_signed_into(): void
    {
        // A tailor created her from the shop floor; she has never set a PIN.
        User::factory()->unclaimed()->create(['phone' => '08031234567']);

        $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => UserFactory::PIN])
            ->assertStatus(422);
    }

    public function test_a_suspended_account_is_refused(): void
    {
        User::factory()->suspended()->create(['phone' => '08031234567']);

        $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => UserFactory::PIN])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');
    }

    public function test_a_lapsed_suspension_lets_her_back_in_without_anybody_running_a_job(): void
    {
        User::factory()->create([
            'phone' => '08031234567',
            'status' => User::STATUS_SUSPENDED,
            'suspended_until' => now()->subDay(),
        ]);

        $this->postJson('/api/login', ['identifier' => '08031234567', 'pin' => UserFactory::PIN])
            ->assertOk();

        $this->assertDatabaseHas('users', ['phone' => '08031234567', 'status' => 'active']);
    }

    public function test_who_am_i(): void
    {
        $user = User::factory()->tailor()->create();
        $user->tailorProfile()->create([
            'business_name' => 'Golden Needle',
            'slug' => 'golden-needle',
            'location' => 'Ikeja',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'tailor')
            ->assertJsonPath('data.tailor_profile.slug', 'golden-needle');
    }

    public function test_the_pin_is_never_returned(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/me')->assertOk()->assertJsonMissingPath('data.password');
    }

    public function test_signing_out_ends_only_this_device(): void
    {
        $user = User::factory()->create();
        $other = $user->createToken('her other phone');
        Sanctum::actingAs($user);

        $this->postJson('/api/logout')->assertOk();

        // The session she is not holding stays alive.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
    }

    public function test_a_token_issued_before_a_suspension_stops_working_at_once(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $user->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        // Not at the next sign-in — now.
        $this->getJson('/api/me')->assertForbidden();
    }

    public function test_signing_in_needs_authentication(): void
    {
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
