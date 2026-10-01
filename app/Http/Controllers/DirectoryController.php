<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\Directory\TailorRanking;
use App\Support\NigerianStates;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The Fashion House directory, server-rendered.
 *
 * "Tailors lack visibility" is one of the six problems in the brief, and the
 * answer to it is a directory a search engine can read and a phone can open
 * without a JavaScript bundle. This is also the page a printed QR code
 * resolves to, which is why it is Blade and why nothing here needs an
 * account.
 */
class DirectoryController extends Controller
{
    public function __construct(private readonly TailorRanking $ranking) {}

    /** Nigeria's states, for the filter. Distance is the problem to solve. */
    public const STATES = NigerianStates::ALL;

    public function index(Request $request): View
    {
        $state = $request->string('state')->value() ?: null;
        $term = $request->string('q')->value() ?: null;

        // A state that is not a state is dropped rather than refused: this is
        // a public page reached from a search engine, and a hand-edited query
        // string should degrade to "everybody", not to an error.
        if ($state && ! in_array($state, self::STATES, true)) {
            $state = null;
        }

        // 24: divisible by the 4, 3 and 2 columns the grid uses, so a full
        // page never ends on a ragged row.
        $tailors = $this->ranking->search($state, $term, 24);

        /*
         * The masthead's numbers and the state chips, over EVERY listed
         * tailor -- not the current search -- so they describe the house, and
         * through the same gate as the listing, so nobody is counted who
         * cannot be found.
         */
        $byState = $this->ranking->listed()
            ->whereNotNull('state')
            ->selectRaw('state, count(*) as tailors')
            ->groupBy('state')
            ->orderByDesc('tailors')
            ->orderBy('state')
            ->pluck('tailors', 'state');

        $house = [
            'tailors' => $this->ranking->listed()->count(),
            'states' => $byState->count(),
            'in_the_making' => Order::query()
                ->whereIn('status', TailorProfile::LIVE_ORDER_STATUSES)
                ->whereIn('tailor_id', $this->ranking->listed()->select('user_id'))
                ->count(),
        ];

        // One query for every gallery on the page rather than one per card.
        $covers = PortfolioItem::query()
            ->visible()
            ->whereIn('tailor_id', $tailors->pluck('user_id'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('tailor_id');

        return view('public.directory', [
            'tailors' => $tailors,
            'covers' => $covers,
            'states' => self::STATES,
            'state' => $state,
            'term' => $term,
            'byState' => $byState,
            'house' => $house,
        ]);
    }

    /**
     * One tailor's page. The address a business card points at.
     *
     * The slug is resolved rather than the id, and the slug never moves --
     * see TailorProfile::slugFor(). Cardboard already in somebody's purse
     * cannot be reissued.
     */
    public function show(string $slug): View
    {
        $profile = TailorProfile::query()
            ->with('user')
            ->where('slug', $slug)
            ->firstOrFail();

        $tailor = $profile->user;

        abort_unless($tailor && $tailor->isTailor(), 404);

        /*
         * NOT A 404 WHEN SHE IS UNAVAILABLE.
         *
         * This address is printed on cardboard in somebody's purse and cannot
         * be reissued, so a dead page here is a permanent physical failure --
         * and it tells the person scanning it that the platform is broken
         * rather than that this tailor is not taking work. The page instead
         * renders a quiet "not currently listed" state: her name, and a way
         * back to the directory, which is the one outcome that still helps
         * the customer standing there with her phone out.
         *
         * She is still absent from the listing and from the sitemap. This is
         * only about the address somebody already has.
         *
         * Two reasons to be unlisted, and they land on the same flag: a
         * suspension, and a lapsed subscription. Neither touches anything
         * else about her account.
         */
        $listed = $tailor->isActive() && $this->subscriptionCovers($tailor);

        if (! $listed) {
            return view('public.tailor-unlisted', [
                'profile' => $profile,
                'tailor' => $tailor,
            ]);
        }

        $gallery = PortfolioItem::query()
            ->visible()
            ->where('tailor_id', $tailor->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $reviews = Review::query()
            ->published()
            ->with('author')
            ->where('subject_id', $tailor->id)
            ->where('direction', Review::CUSTOMER_TO_TAILOR)
            ->latest('published_at')
            ->take(20)
            ->get();

        /*
         * The breakdown is over EVERY published review, not the twenty shown:
         * "5 stars: 41" beside a list of twenty would otherwise disagree with
         * itself, and the summary is the part most people read.
         */
        $ratingCounts = Review::query()
            ->published()
            ->where('subject_id', $tailor->id)
            ->where('direction', Review::CUSTOMER_TO_TAILOR)
            ->selectRaw('rating, count(*) as total')
            ->groupBy('rating')
            ->pluck('total', 'rating');

        /*
         * Somewhere to go next. A visitor who scanned her card and finds she is
         * busy, or not quite right, should not hit a dead end -- the next
         * tailor in her state is the most useful thing on the page for them.
         * Through the ranking, so the same gate applies; the whole house when
         * her state has nobody else.
         */
        $nearby = collect($this->ranking->search($profile->state, null, 5)->items())
            ->reject(fn (TailorProfile $other) => $other->is($profile));

        if ($nearby->isEmpty() && $profile->state) {
            $nearby = collect($this->ranking->search(null, null, 5)->items())
                ->reject(fn (TailorProfile $other) => $other->is($profile));
        }

        $nearby = $nearby->take(4)->values();

        $nearbyCovers = PortfolioItem::query()
            ->visible()
            ->whereIn('tailor_id', $nearby->pluck('user_id'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('tailor_id');

        return view('public.tailor', [
            'profile' => $profile,
            'tailor' => $tailor,
            'gallery' => $gallery,
            'reviews' => $reviews,
            'reviewTotal' => (int) $ratingCounts->sum(),
            'ratingCounts' => $ratingCounts,
            // A count, never the orders -- the same fact the directory card shows.
            'live' => $profile->liveOrders()->count(),
            'nearby' => $nearby,
            'nearbyCovers' => $nearbyCovers,
            'nearbyIsLocal' => $nearby->isNotEmpty() && $nearby->every(fn ($o) => $o->state === $profile->state),
        ]);
    }

    /**
     * Whether her listing is paid up.
     *
     * Reads the timestamps through Subscription::covers(), never the status
     * label -- the label is only as fresh as the last thing that recomputed
     * it. Grace counts: a bank transfer settling a day late must not take
     * down the page a printed card points at.
     */
    private function subscriptionCovers(User $tailor): bool
    {
        if (! (bool) PlatformSetting::get(PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION, true)) {
            return true;
        }

        return $tailor->subscription?->covers() ?? false;
    }

    /**
     * A sitemap, because the point of this section is being found.
     *
     * Generated on request rather than written to disk: there is no queue to
     * regenerate it on, and a directory of this size costs one indexed query.
     */
    public function sitemap(): Response
    {
        $profiles = TailorProfile::query()
            ->whereHas('user', fn ($q) => $q
                ->where('role', User::ROLE_TAILOR)
                ->where('status', User::STATUS_ACTIVE)
                // Unlisted pages still resolve, for the printed card -- but
                // there is no reason to invite a crawler to one.
                ->when(
                    (bool) PlatformSetting::get(PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION, true),
                    fn ($u) => $u->whereHas('subscription', fn ($s) => $s->covering()),
                ))
            ->latest('updated_at')
            ->take(5000)
            ->get(['slug', 'updated_at']);

        return response()
            ->view('public.sitemap', ['profiles' => $profiles])
            ->header('Content-Type', 'application/xml');
    }
}
