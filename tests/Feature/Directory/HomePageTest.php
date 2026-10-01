<?php

namespace Tests\Feature\Directory;

use App\Models\Subscription;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The landing page: real tailors, and a way into the app.
 *
 * It used to show five invented shops and link nowhere -- every "Join" led
 * back to its own paragraph and there was no "Sign in" at all.
 */
class HomePageTest extends TestCase
{
    use RefreshDatabase;

    private function listedTailor(string $name, bool $subscribed = true): User
    {
        $user = User::factory()->tailor()->create();
        TailorProfile::factory()->create([
            'user_id' => $user->id,
            'business_name' => $name,
            'slug' => TailorProfile::slugFor($name),
            'state' => 'Lagos',
        ]);

        if ($subscribed) {
            Subscription::forTailor($user)->forceFill([
                'current_period_end' => now()->addDays(30),
                'grace_ends_at' => now()->addDays(37),
            ])->save();
        }

        return $user;
    }

    public function test_it_shows_real_listed_tailors_linking_to_their_pages(): void
    {
        $this->listedTailor('Golden Needle');

        $this->get('/')
            ->assertOk()
            ->assertSee('Golden Needle')
            ->assertSee(route('tailor', 'golden-needle'), false)
            ->assertSee('See all 1 tailor');
    }

    /** The same gate as /tailors: nobody here that the directory would hide. */
    public function test_it_never_shows_a_tailor_the_directory_would_hide(): void
    {
        $this->listedTailor('Lapsed Lace', subscribed: false);

        $suspended = $this->listedTailor('Paused Pins');
        $suspended->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->get('/')
            ->assertOk()
            ->assertDontSee('Lapsed Lace')
            ->assertDontSee('Paused Pins');
    }

    /** Nobody listed yet: an invitation, never an empty carousel. */
    public function test_with_nobody_listed_it_invites_tailors_instead(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('The Fashion House is opening.')
            ->assertDontSee('Tailors taking work now.');
    }

    public function test_it_links_into_the_app_to_sign_in_and_to_join(): void
    {
        config(['app.frontend_url' => 'https://app.example.test/']);

        $this->get('/')
            ->assertOk()
            ->assertSee('https://app.example.test/sign-in', false)
            ->assertSee('https://app.example.test/join?as=tailor', false)
            // The nav's "Join" opens the join page itself, not an anchor.
            ->assertSee('href="https://app.example.test/join"', false)
            ->assertDontSee('href="/#join"', false)
            // "Find a tailor" goes to the real directory, not to an anchor.
            ->assertSee(route('directory'), false);
    }
}
