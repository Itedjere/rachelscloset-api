<?php

namespace Tests\Feature\Directory;

use App\Models\Order;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\Directory\TailorRanking;
use App\Services\Reviews\RecalculateTailorRating;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Fashion House directory.
 *
 * Public, server-rendered, and reachable without an account -- a QR code on
 * cardboard resolves here, and "tailors lack visibility" is answered by a
 * page a search engine can read.
 */
class DirectoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A listed tailor.
     *
     * Subscribed, because Section 14 made the listing the thing she buys --
     * a tailor with no term is not in the directory at all, which is tested
     * where that rule lives rather than being worked around here.
     */
    private function tailor(string $name, ?string $state = null, int $completed = 0): User
    {
        $user = User::factory()->tailor()->create();

        TailorProfile::factory()->create([
            'user_id' => $user->id,
            'business_name' => $name,
            'slug' => TailorProfile::slugFor($name),
            'state' => $state,
            'orders_completed' => $completed,
        ]);

        Subscription::forTailor($user)->forceFill([
            'current_period_end' => now()->addDays(30),
            'grace_ends_at' => now()->addDays(37),
        ])->save();

        return $user;
    }

    /** Publishes `$count` reviews at `$rating`, and syncs the denormalised average. */
    private function rate(User $tailor, int $count, int $rating): void
    {
        for ($i = 0; $i < $count; $i++) {
            $customer = User::factory()->customer()->create();
            $order = Order::factory()->create([
                'customer_id' => $customer->id,
                'tailor_id' => $tailor->id,
            ]);
            $order->forceFill(['status' => Order::COMPLETED])->save();

            $review = Review::create([
                'order_id' => $order->id,
                'direction' => Review::CUSTOMER_TO_TAILOR,
                'author_id' => $customer->id,
                'subject_id' => $tailor->id,
                'rating' => $rating,
            ]);
            $review->publish();
        }

        app(RecalculateTailorRating::class)->handle($tailor);
    }

    /* ===================================================================== */

    public function test_the_directory_is_open_to_anybody(): void
    {
        $this->tailor('Mama Ngozi Couture', 'Lagos');

        $this->get('/tailors')
            ->assertOk()
            ->assertSee('Mama Ngozi Couture');
    }

    public function test_a_profile_page_is_open_to_anybody(): void
    {
        $tailor = $this->tailor('Bisi Bespoke', 'Oyo');
        $slug = $tailor->tailorProfile->slug;

        $this->get("/t/{$slug}")
            ->assertOk()
            ->assertSee('Bisi Bespoke')
            ->assertSee('Oyo');
    }

    public function test_an_unknown_slug_is_a_404(): void
    {
        $this->get('/t/nobody-here')->assertNotFound();
    }

    /**
     * The shopfront closes; her orders and money are untouched.
     *
     * She leaves the LISTING. Her own address keeps resolving, to a quiet
     * "not listed" page -- Section 16 changed that from a 404, because the
     * address is printed on cardboard that cannot be reissued and a dead
     * page reads as a broken platform rather than an absent tailor.
     */
    public function test_a_suspended_tailor_disappears_from_the_listing(): void
    {
        $tailor = $this->tailor('Gone Fishing', 'Kano');
        $slug = $tailor->tailorProfile->slug;

        $tailor->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->get('/tailors')->assertOk()->assertDontSee('Gone Fishing');

        $this->get("/t/{$slug}")
            ->assertOk()
            ->assertSee('not listed at the moment')
            // And nothing of hers is on it.
            ->assertDontSee('Message on WhatsApp');
    }

    public function test_the_state_filter_narrows_the_list(): void
    {
        $this->tailor('Lagos Lace', 'Lagos');
        $this->tailor('Kano Kaftans', 'Kano');

        $this->get('/tailors?state=Lagos')
            ->assertOk()
            ->assertSee('Lagos Lace')
            ->assertDontSee('Kano Kaftans');
    }

    /** A hand-edited query string degrades to "everybody", never to an error. */
    public function test_an_invented_state_is_ignored_rather_than_refused(): void
    {
        $this->tailor('Lagos Lace', 'Lagos');

        $this->get('/tailors?state=Atlantis')
            ->assertOk()
            ->assertSee('Lagos Lace');
    }

    public function test_search_matches_the_business_name(): void
    {
        $this->tailor('Adire House', 'Ogun');
        $this->tailor('Kano Kaftans', 'Kano');

        $this->get('/tailors?q=Adire')
            ->assertOk()
            ->assertSee('Adire House')
            ->assertDontSee('Kano Kaftans');
    }

    /* ===================================================================== */

    /**
     * THE RANKING RULE THAT MATTERS.
     *
     * A raw average puts one five-star review above forty averaging 4.7,
     * which is both wrong and the cheapest thing in the world to
     * manufacture. The Bayesian prior is what stops it.
     */
    public function test_one_five_star_review_does_not_outrank_many_good_ones(): void
    {
        $established = $this->tailor('Established House', 'Lagos', completed: 40);
        $this->rate($established, 20, 5);
        // Drag the average just below five, the way real feedback does.
        $this->rate($established, 6, 4);

        $newcomer = $this->tailor('One Review Wonder', 'Lagos', completed: 1);
        $this->rate($newcomer, 1, 5);

        $ranked = app(TailorRanking::class)->search(null, null);

        $this->assertSame(
            'Established House',
            $ranked->first()->business_name,
            'twenty-six reviews must outrank one',
        );
    }

    /** Without an allowance a new tailor is last forever and can never move. */
    public function test_a_brand_new_tailor_outranks_a_badly_rated_one(): void
    {
        $bad = $this->tailor('Poorly Rated', 'Lagos', completed: 10);
        $this->rate($bad, 8, 2);

        $this->tailor('Just Joined', 'Lagos', completed: 0);

        $ranked = app(TailorRanking::class)->search(null, null);

        $this->assertSame('Just Joined', $ranked->first()->business_name);
    }

    /**
     * The newcomer allowance must not overshoot.
     *
     * It has to clear a badly-rated tailor and stay under what one genuine
     * review earns -- otherwise an empty profile outranks somebody a real
     * customer actually praised, which is the opposite of the point.
     */
    public function test_one_real_review_still_outranks_an_empty_profile(): void
    {
        $reviewed = $this->tailor('One Happy Customer', 'Lagos', completed: 1);
        $this->rate($reviewed, 1, 5);

        $this->tailor('Empty Profile', 'Lagos', completed: 0);

        $ranked = app(TailorRanking::class)->search(null, null);

        $this->assertSame('One Happy Customer', $ranked->first()->business_name);
        $this->assertSame('Empty Profile', $ranked->last()->business_name);
    }

    /** A held review is not a fact about anybody, so it must not rank. */
    public function test_a_held_review_does_not_affect_the_listing(): void
    {
        $tailor = $this->tailor('Quiet Tailor', 'Lagos');
        $customer = User::factory()->customer()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'tailor_id' => $tailor->id,
        ]);

        Review::create([
            'order_id' => $order->id,
            'direction' => Review::CUSTOMER_TO_TAILOR,
            'author_id' => $customer->id,
            'subject_id' => $tailor->id,
            'rating' => 5,
        ])->hold('0.00');

        $this->get('/tailors')->assertOk()->assertDontSee('from 1 review');

        $profile = app(TailorRanking::class)->search(null, null)->first();
        $this->assertSame(0, $profile->review_count);
    }

    /* ===================================================================== */

    public function test_the_gallery_appears_on_the_profile(): void
    {
        $tailor = $this->tailor('Gallery House', 'Lagos');

        $item = PortfolioItem::factory()->create([
            'tailor_id' => $tailor->id,
            'caption' => 'Coral beaded gown',
        ]);

        $this->get('/t/'.$tailor->tailorProfile->slug)
            ->assertOk()
            ->assertSee('Coral beaded gown')
            ->assertSee(basename($item->path));
    }

    public function test_a_hidden_photograph_is_absent_from_the_profile(): void
    {
        $tailor = $this->tailor('Gallery House', 'Lagos');

        $item = PortfolioItem::factory()->create([
            'tailor_id' => $tailor->id,
            'caption' => 'Should not appear',
        ]);
        $item->forceFill(['hidden_at' => now()])->save();

        $this->get('/t/'.$tailor->tailorProfile->slug)
            ->assertOk()
            ->assertDontSee('Should not appear');
    }

    public function test_published_reviews_appear_and_held_ones_do_not(): void
    {
        $tailor = $this->tailor('Reviewed House', 'Lagos');
        $this->rate($tailor, 1, 5);

        Review::query()->first()->forceFill(['body' => 'She was wonderful.'])->save();

        $customer = User::factory()->customer()->create();
        $order = Order::factory()->create([
            'customer_id' => $customer->id, 'tailor_id' => $tailor->id,
        ]);
        Review::create([
            'order_id' => $order->id,
            'direction' => Review::CUSTOMER_TO_TAILOR,
            'author_id' => $customer->id,
            'subject_id' => $tailor->id,
            'rating' => 5,
            'body' => 'This one is waiting to be checked.',
        ])->hold('0.00');

        $this->get('/t/'.$tailor->tailorProfile->slug)
            ->assertOk()
            ->assertSee('She was wonderful.')
            ->assertDontSee('This one is waiting to be checked.');
    }

    /* ===================================================================== */

    public function test_the_sitemap_lists_every_active_tailor(): void
    {
        $listed = $this->tailor('Listed House', 'Lagos');
        $hidden = $this->tailor('Suspended House', 'Lagos');
        $hidden->forceFill(['status' => User::STATUS_SUSPENDED])->save();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml')
            ->assertSee($listed->tailorProfile->slug)
            ->assertDontSee($hidden->tailorProfile->slug);
    }

    public function test_robots_points_at_the_sitemap_and_closes_the_api(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /api/')
            ->assertSee('sitemap.xml');
    }
}
