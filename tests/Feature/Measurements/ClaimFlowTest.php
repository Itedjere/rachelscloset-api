<?php

namespace Tests\Feature\Measurements;

use App\Models\ClaimToken;
use App\Models\TailorCustomerLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Taking over a profile a tailor created for you.
 *
 * No SMS, no email. The tailor and the customer are standing together, so
 * two of the three channels send nothing at all and the third is her own
 * WhatsApp.
 */
class ClaimFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $tailor;

    private User $walkIn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tailor = User::factory()->tailor()->create();
        $this->walkIn = User::factory()->customer()->unclaimed()->create([
            'name' => 'Amaka Eze',
            'phone' => '08031112233',
        ]);
    }

    private function issue(): array
    {
        Sanctum::actingAs($this->tailor);

        $response = $this->postJson("/api/customers/{$this->walkIn->id}/claim-invite")->assertOk();

        return $response->json('data');
    }

    /* ===================================================================== */

    public function test_the_tailor_gets_all_three_channels_at_once(): void
    {
        $data = $this->issue();

        $this->assertMatchesRegularExpression('/^\d{6}$/', $data['code']);
        $this->assertStringContainsString('/claim/', $data['link']);
        $this->assertStringStartsWith('<?xml', $data['qr_svg']);
        $this->assertStringStartsWith('https://wa.me/', $data['whatsapp_url']);
    }

    /** Plaintext exists in that response and nowhere else. */
    public function test_neither_credential_is_stored_readable(): void
    {
        $data = $this->issue();

        $token = ClaimToken::query()->sole();

        $this->assertNotSame($data['code'], $token->code_hash);
        $this->assertTrue(Hash::check($data['code'], $token->code_hash));

        // The link token is hashed too, just not slowly -- 48 random
        // characters need no work factor.
        $this->assertStringNotContainsString($token->token_hash, $data['link']);
    }

    public function test_re_issuing_kills_the_previous_code(): void
    {
        $first = $this->issue();
        $second = $this->issue();

        $this->assertSame(2, ClaimToken::query()->count());

        // The old link no longer resolves.
        $this->getJson('/api/claim/'.$this->tokenFrom($first['link']))->assertNotFound();
        $this->getJson('/api/claim/'.$this->tokenFrom($second['link']))->assertOk();
    }

    public function test_a_claimed_customer_cannot_be_invited_again(): void
    {
        $claimed = User::factory()->customer()->create();

        Sanctum::actingAs($this->tailor);

        $this->postJson("/api/customers/{$claimed->id}/claim-invite")->assertStatus(422);
    }

    public function test_a_customer_cannot_issue_an_invite(): void
    {
        Sanctum::actingAs(User::factory()->customer()->create());

        $this->postJson("/api/customers/{$this->walkIn->id}/claim-invite")->assertNotFound();
    }

    /* ===================================================================== */

    /**
     * The preview is deliberately thin: enough to reassure somebody who just
     * scanned a QR code off a stranger's phone, not enough to be worth
     * guessing tokens for.
     */
    public function test_the_preview_names_the_profile_and_who_invited_her(): void
    {
        $data = $this->issue();

        $this->getJson('/api/claim/'.$this->tokenFrom($data['link']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Amaka Eze')
            ->assertJsonPath('data.invited_by', $this->tailor->name)
            ->assertJsonMissingPath('data.phone');
    }

    public function test_an_invented_token_previews_nothing(): void
    {
        $this->getJson('/api/claim/'.str_repeat('a', 48))->assertNotFound();
    }

    /* ===================================================================== */

    public function test_she_claims_by_link_and_is_signed_in(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'phone', 'role']]);

        $this->assertTrue($this->walkIn->fresh()->isClaimed());
        $this->assertNotNull(ClaimToken::query()->sole()->used_at);
    }

    /** The channel for when they are not together: six digits and her number. */
    public function test_she_claims_by_spoken_code_and_her_phone_number(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $this->assertTrue($this->walkIn->fresh()->isClaimed());
    }

    /** Six digits are not unique across the platform; the number scopes them. */
    public function test_the_right_code_against_the_wrong_number_fails(): void
    {
        $data = $this->issue();

        $other = User::factory()->customer()->unclaimed()->create(['phone' => '08039998877']);

        $this->postJson('/api/claim', [
            'code' => $data['code'],
            'phone' => '08039998877',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);

        $this->assertFalse($other->fresh()->isClaimed());
        $this->assertFalse($this->walkIn->fresh()->isClaimed());
    }

    public function test_a_wrong_code_fails(): void
    {
        $this->issue();

        $this->postJson('/api/claim', [
            'code' => '000000',
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);
    }

    /** A code read down the phone with no address beside it is a key with no door. */
    public function test_the_invite_carries_the_address_to_read_out(): void
    {
        $data = $this->issue();

        $this->assertStringEndsWith('/claim', $data['claim_page']);
        $this->assertStringStartsWith($data['claim_page'].'/', $data['link']);
    }

    /**
     * The code is checked before she is shown the PIN boxes, so she never
     * faces two rows of six at once -- and checking uses nothing up.
     */
    public function test_a_spoken_code_is_checked_before_she_chooses_a_pin(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim/check', ['code' => $data['code'], 'phone' => '0803 111 2233'])
            ->assertOk()
            ->assertJsonPath('data.name', $this->walkIn->name)
            ->assertJsonPath('data.invited_by', $this->tailor->name);

        $this->assertNull(ClaimToken::query()->sole()->used_at);
        $this->assertFalse($this->walkIn->fresh()->isClaimed());

        // And the real claim still works afterwards.
        $this->postJson('/api/claim', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();
    }

    /** Same single refusal as the claim, so it cannot tell a guesser which part was wrong. */
    public function test_the_check_refuses_a_wrong_code_a_wrong_number_and_an_expired_one_alike(): void
    {
        $data = $this->issue();
        User::factory()->customer()->unclaimed()->create(['phone' => '08039998877']);

        $messages = [
            $this->postJson('/api/claim/check', ['code' => '000000', 'phone' => '08031112233'])
                ->assertStatus(422)->json('errors.code.0'),
            $this->postJson('/api/claim/check', ['code' => $data['code'], 'phone' => '08039998877'])
                ->assertStatus(422)->json('errors.code.0'),
            $this->postJson('/api/claim/check', ['code' => $data['code'], 'phone' => '08035550000'])
                ->assertStatus(422)->json('errors.code.0'),
        ];

        ClaimToken::query()->update(['expires_at' => now()->subMinute()]);

        $messages[] = $this->postJson('/api/claim/check', ['code' => $data['code'], 'phone' => '08031112233'])
            ->assertStatus(422)->json('errors.code.0');

        $this->assertCount(1, array_unique($messages));
    }

    /**
     * Checking is a second door onto the same six-digit guess, so it spends
     * the SAME allowance as claiming. Eight checks leave no claims.
     */
    public function test_checking_and_claiming_share_one_allowance_of_guesses(): void
    {
        $data = $this->issue();

        for ($i = 0; $i < 8; $i++) {
            $this->postJson('/api/claim/check', ['code' => '000000', 'phone' => '08031112233'])
                ->assertStatus(422);
        }

        $this->postJson('/api/claim', [
            'code' => $data['code'],
            'phone' => '08031112233',
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(429);

        $this->assertFalse($this->walkIn->fresh()->isClaimed());
    }

    public function test_an_expired_invitation_fails(): void
    {
        $data = $this->issue();

        ClaimToken::query()->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertStatus(422);

        $this->assertFalse($this->walkIn->fresh()->isClaimed());
    }

    public function test_an_invitation_is_single_use(): void
    {
        $data = $this->issue();
        $token = $this->tokenFrom($data['link']);

        $this->postJson('/api/claim', [
            'token' => $token, 'pin' => '493028', 'pin_confirmation' => '493028',
        ])->assertOk();

        $this->postJson('/api/claim', [
            'token' => $token, 'pin' => '551937', 'pin_confirmation' => '551937',
        ])->assertStatus(422);
    }

    /** The PIN rules apply here exactly as they do at registration. */
    public function test_a_weak_pin_is_refused(): void
    {
        $data = $this->issue();

        foreach (['111111', '123456'] as $weak) {
            $this->postJson('/api/claim', [
                'token' => $this->tokenFrom($data['link']),
                'pin' => $weak,
                'pin_confirmation' => $weak,
            ])->assertStatus(422);
        }

        $this->assertFalse($this->walkIn->fresh()->isClaimed());
    }

    /** Digits out of her own phone number, which is the one people reach for. */
    public function test_a_pin_taken_from_her_phone_number_is_refused(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '111223',
            'pin_confirmation' => '111223',
        ])->assertStatus(422);
    }

    /* ===================================================================== */

    /**
     * Claiming through a tailor's invitation grants that tailor a link.
     *
     * This is the moment the plan calls "cross-tailor history begins when a
     * real person consents": she was handed the code by this tailor, the
     * claim screen says plainly that it lets her keep seeing the
     * measurements, and it is one tap to take back afterwards.
     */
    public function test_claiming_grants_the_inviting_tailor_a_link(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $link = TailorCustomerLink::query()->sole();

        $this->assertSame($this->tailor->id, $link->tailor_id);
        $this->assertSame($this->walkIn->id, $link->customer_id);
        $this->assertTrue($link->isGranted());
    }

    /** And she can see it, and take it back, from her own screen. */
    public function test_she_can_immediately_see_and_revoke_that_link(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        Sanctum::actingAs($this->walkIn->fresh());

        $this->getJson('/api/measurement-access')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.tailor.id', $this->tailor->id)
            ->assertJsonPath('data.0.granted', true);

        $link = TailorCustomerLink::query()->sole();
        $this->postJson("/api/measurement-access/{$link->id}/revoke")->assertOk();

        $this->assertFalse($link->fresh()->isGranted());
    }

    /** She can sign in afterwards with the PIN she just chose. */
    public function test_the_pin_she_chose_actually_signs_her_in(): void
    {
        $data = $this->issue();

        $this->postJson('/api/claim', [
            'token' => $this->tokenFrom($data['link']),
            'pin' => '493028',
            'pin_confirmation' => '493028',
        ])->assertOk();

        $this->postJson('/api/login', ['identifier' => '08031112233', 'pin' => '493028'])
            ->assertOk()
            ->assertJsonStructure(['token']);
    }

    private function tokenFrom(string $link): string
    {
        return substr($link, strrpos($link, '/') + 1);
    }
}
