<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\Directory\TailorRanking;
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
    public const STATES = [
        'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue',
        'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu',
        'FCT', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
        'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun',
        'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara',
    ];

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

        $tailors = $this->ranking->search($state, $term);

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

        // A suspended account disappears from the public site entirely. Her
        // orders and her money are untouched; this is only the shopfront.
        abort_unless($tailor && $tailor->isActive() && $tailor->isTailor(), 404);

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

        return view('public.tailor', [
            'profile' => $profile,
            'tailor' => $tailor,
            'gallery' => $gallery,
            'reviews' => $reviews,
        ]);
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
                ->where('status', User::STATUS_ACTIVE))
            ->latest('updated_at')
            ->take(5000)
            ->get(['slug', 'updated_at']);

        return response()
            ->view('public.sitemap', ['profiles' => $profiles])
            ->header('Content-Type', 'application/xml');
    }
}
