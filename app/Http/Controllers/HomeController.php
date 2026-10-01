<?php

namespace App\Http\Controllers;

use App\Models\PortfolioItem;
use App\Services\Directory\TailorRanking;
use Illuminate\View\View;

/**
 * The landing page, with real tailors in it.
 *
 * It was a static view: "Tailors taking work now" showed five invented shops
 * with stock photographs, labelled illustrative at the foot of the page, while
 * the real directory sat one click away and nothing on the page linked to it.
 * Now it shows the top of that directory, through the same TailorRanking that
 * orders /tailors -- so the subscription gate, suspension and the newcomer
 * allowance all apply here exactly as there, and nothing can be listed on the
 * front page that the directory would hide.
 */
class HomeController extends Controller
{
    /** Enough to fill the carousel on a wide screen, few enough to load fast. */
    private const SHOWN = 8;

    public function __construct(private readonly TailorRanking $ranking) {}

    public function __invoke(): View
    {
        $tailors = $this->ranking->search(null, null, self::SHOWN);

        // One query for every card's cover, not one per card.
        $covers = PortfolioItem::query()
            ->visible()
            ->whereIn('tailor_id', $tailors->pluck('user_id'))
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy('tailor_id');

        return view('public.home', [
            'tailors' => $tailors->getCollection(),
            'covers' => $covers,
            'tailorsTotal' => $tailors->total(),
        ]);
    }
}
