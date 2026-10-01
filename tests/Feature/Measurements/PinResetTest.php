<?php

namespace Tests\Feature\Measurements;

use App\Models\ClaimToken;
use App\Models\User;
use App\Notifications\PinWasReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Getting back in after forgetting a PIN.
 *
 * This closes the hole the rest of the design would otherwise leave: sign-in
 * is a phone number and six digits, nothing here sends an SMS or requires an
 * email address, and so without this a tailor who forgets her number is
 * locked out of her business permanently.
 *
 * The same three free channels as claiming, admin-issued.
 */
class PinResetTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tailor;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->admin = User::factory()->admin()->create();
        $this->tailor = User::factory()->tailor()->create([
            'name' => 'Ngozi Okeke',
            'phone' => '08031112233',
        ]);
    }

    private function issue(): array
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson("/api/admin/users/{$this->tailor->id}/pin-reset")
            ->assertOk()
            ->json('data');
    }

    private function tokenFrom(string $link): string
    {
        return substr($link, strrpos($link, '/') + 1);
    }

    /* ===================================================================== */

    public function test_the_admin_gets_all_three_free_channels(): void
    {
        $data = $this->issue();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $data['code']);
        $this->assertStringContainsString('/reset/', $data['link']);
        $this->assertStringStartsWith('<?xml', $data['qr_svg']);

        // Her own WhatsApp, with the number prefilled. Not the Business API,
        // so nobody is billed per conversation.
        // wa.me needs the international number with no leading zero. This
        // test used to assert 08031112233 appeared, which pinned a link that
        // opened a chat with nobody.
        $this->assertStringStartsWith('https://wa.me/2348031112233?text=', $data['whatsapp_url']);
        $this->assertStringEndsWith('/reset', $data['reset_page']);
    }

    /** Checked before she sees the PIN boxes; checking uses nothing up. */
    public function test_a_spoken_reset_code_is_checked_before_she_chooses_a_pin(): void
    {
        $data = $this->issue();
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/reset/check', ['code' => $data['code'], 'phone' => '0803 111 2233'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ngozi Okeke');

        $this->assertNull(ClaimToken::query()->sole()->used_at);

        $this->postJson('/api/reset', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();
    }

    public function test_the_reset_check_refuses_with_the_one_message(): void
    {
        $data = $this->issue();

        $wrongCode = $this->postJson('/api/reset/check', ['code' => '000000', 'phone' => '08031112233'])
            ->assertStatus(422)->json('errors.code.0');
        $wrongPhone = $this->postJson('/api/reset/check', ['code' => $data['code'], 'phone' => '08035550000'])
            ->assertStatus(422)->json('errors.code.0');

        $this->assertSame($wrongCode, $wrongPhone);
    }

    /** A second door onto the same guess spends the same, tighter allowance. */
    public function test_checking_and_resetting_share_one_allowance_of_guesses(): void
    {
        $data = $this->issue();

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/reset/check', ['code' => '000000', 'phone' => '08031112233'])
                ->assertStatus(422);
        }

        $this->postJson('/api/reset', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(429);
    }

    /** Plaintext exists in that response and nowhere else. */
    public function test_neither_credential_is_stored_readable(): void
    {
        $data = $this->issue();
        $token = ClaimToken::query()->sole();

        $this->assertNotSame($data['code'], $token->code_hash);
        $this->assertTrue(Hash::check($data['code'], $token->code_hash));
        $this->assertStringNotContainsString($token->token_hash, $data['link']);
    }

    /**
     * A reset code lives far less long than a claim code.
     *
     * A claim opens an empty profile; this opens an account with orders and
     * money in it, and it is issued while somebody is on the phone.
     */
    public function test_a_reset_expires_sooner_than_an_invitation(): void
    {
        $this->issue();

        $expires = ClaimToken::query()->sole()->expires_at;

        $this->assertTrue($expires->lessThan(now()->addHours(ClaimToken::LIFETIME_HOURS)));
        $this->assertSame(ClaimToken::RESET_LIFETIME_HOURS, (int) round(now()->diffInHours($expires)));
    }

    /**
     * ADMIN-ISSUED, and that is a security decision.
     *
     * A tailor able to reset her own customer's PIN could take over an
     * account holding that customer's orders, money and measurements -- and
     * she is the person with the motive.
     */
    public function test_a_tailor_cannot_issue_a_reset_for_her_customer(): void
    {
        $customer = User::factory()->customer()->create();

        Sanctum::actingAs($this->tailor);

        $this->postJson("/api/admin/users/{$customer->id}/pin-reset")->assertForbidden();
        $this->assertSame(0, ClaimToken::query()->count());
    }

    /** An account never set up is claimed, not reset. */
    public function test_an_unclaimed_profile_is_told_to_use_the_invitation_instead(): void
    {
        $walkIn = User::factory()->customer()->unclaimed()->create();

        Sanctum::actingAs($this->admin);

        $this->postJson("/api/admin/users/{$walkIn->id}/pin-reset")->assertStatus(422);
    }

    /* ===================================================================== */

    public function test_the_preview_names_her_and_nothing_else(): void
    {
        $data = $this->issue();

        $this->getJson('/api/reset/'.$this->tokenFrom($data['link']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Ngozi Okeke')
            ->assertJsonMissingPath('data.phone');
    }

    public function test_an_invented_token_previews_nothing(): void
    {
        $this->getJson('/api/reset/'.str_repeat('a', 48))->assertNotFound();
    }

    /** A claim token must not work on the reset endpoint, or the reverse. */
    public function test_the_two_purposes_do_not_cross(): void
    {
        $walkIn = User::factory()->customer()->unclaimed()->create();
        $claim = ClaimToken::issue($walkIn, $this->admin, ClaimToken::CLAIM);

        $this->getJson('/api/reset/'.$claim['link_token'])->assertNotFound();

        $this->postJson('/api/reset', [
            'token' => $claim['link_token'],
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);

        $reset = ClaimToken::issue($this->tailor, $this->admin, ClaimToken::PIN_RESET);

        $this->postJson('/api/claim', [
            'token' => $reset['link_token'],
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);
    }

    /* ===================================================================== */

    public function test_she_resets_by_link_and_can_sign_in_with_the_new_pin(): void
    {
        $data = $this->issue();

        $this->postJson('/api/reset', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user']);

        $this->postJson('/api/login', ['identifier' => '08031112233', 'pin' => '493028'])
            ->assertOk();

        Notification::assertSentTo($this->tailor, PinWasReset::class);
    }

    /** The channel for a phone call: six digits and her own number. */
    public function test_she_resets_by_spoken_code_and_her_phone_number(): void
    {
        $data = $this->issue();

        $this->postJson('/api/reset', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $this->assertTrue(Hash::check('493028', $this->tailor->fresh()->password));
    }

    public function test_the_right_code_against_the_wrong_number_fails(): void
    {
        $data = $this->issue();
        $other = User::factory()->customer()->create(['phone' => '08039998877']);

        $this->postJson('/api/reset', [
            'code' => $data['code'],
            'phone' => '08039998877',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);

        $this->assertFalse(Hash::check('493028', $other->fresh()->password));
    }

    public function test_an_expired_reset_fails(): void
    {
        $data = $this->issue();

        ClaimToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/reset', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);
    }

    public function test_a_reset_is_single_use(): void
    {
        $data = $this->issue();
        $token = $this->tokenFrom($data['link']);

        $this->postJson('/api/reset', [
            'token' => $token, 'pin' => '493028', 'pin_confirmation' => '493028',
        ])->assertOk();

        $this->postJson('/api/reset', [
            'token' => $token, 'pin' => '551937', 'pin_confirmation' => '551937',
        ])->assertStatus(422);
    }

    /** The PIN rules apply exactly as they do at registration. */
    public function test_a_weak_pin_or_one_from_her_own_number_is_refused(): void
    {
        $data = $this->issue();
        $token = $this->tokenFrom($data['link']);

        foreach (['111111', '123456', '111223'] as $weak) {
            $this->postJson('/api/reset', [
                'token' => $token, 'pin' => $weak, 'pin_confirmation' => $weak,
            ])->assertStatus(422);
        }
    }

    /* ===================================================================== */

    /**
     * EVERY OTHER SESSION DIES.
     *
     * If she forgot her PIN the old tokens are hers and worthless. If
     * somebody else had got in -- which is a reason to reset -- leaving their
     * session alive would make the whole reset theatre.
     */
    public function test_resetting_kills_every_existing_session(): void
    {
        $old = $this->tailor->createToken('old')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$old)
            ->getJson('/api/me')->assertOk();

        /*
         * Issued directly rather than through the admin endpoint, on purpose.
         * Sanctum::actingAs fakes the guard for the remainder of the test, so
         * a later bearer-token request would be answered as the admin and
         * this assertion would pass without proving anything.
         */
        $issued = ClaimToken::issue($this->tailor, $this->admin, ClaimToken::PIN_RESET);

        $this->postJson('/api/reset', [
            'token' => $issued['link_token'],
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $this->assertSame(
            1,
            $this->tailor->tokens()->count(),
            'only the token minted by the reset itself should remain',
        );

        // The guard caches the user it resolved on the earlier request in
        // this test; without this the stale resolution answers instead of the
        // (now deleted) token.
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', 'Bearer '.$old)
            ->getJson('/api/me')->assertUnauthorized();
    }

    /** A second outstanding code is a second chance to guess, for no benefit. */
    public function test_a_reset_spends_any_other_outstanding_code(): void
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->postJson('/api/reset', [
            'token' => $this->tokenFrom($second['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        // The earlier one was already expired by re-issuing; neither works now.
        foreach ([$first, $second] as $issued) {
            $this->postJson('/api/reset', [
                'token' => $this->tokenFrom($issued['link']),
                'pin' => '551937',
                'pin_confirmation' => '551937',
            ])->assertStatus(422);
        }
    }

    /** The one message that tells somebody their account was taken over. */
    public function test_she_is_told_her_pin_changed(): void
    {
        $data = $this->issue();

        $this->postJson('/api/reset', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        Notification::assertSentTo(
            $this->tailor,
            PinWasReset::class,
            fn (PinWasReset $n) => $n->type() === 'pin_reset',
        );
    }
}
