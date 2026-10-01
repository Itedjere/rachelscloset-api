<?php

namespace App\Services\Directory;

use App\Models\PlatformSetting;
use App\Models\Review;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Who appears first in the directory.
 *
 * The plan names this as where gaming will happen, so the formula lives in
 * one place and its inputs are settings rather than numbers scattered through
 * a query.
 *
 * Three ingredients:
 *
 * 1. A BAYESIAN-ADJUSTED AVERAGE. A raw average puts one five-star review
 *    above forty averaging 4.7, which is both wrong and the cheapest thing in
 *    the world to manufacture. Each tailor is instead treated as starting
 *    with a few notional reviews at the middle of the scale, so a rating only
 *    moves away from the middle as real evidence accumulates.
 *
 * 2. PROXIMITY, as a filter rather than a score. Distance is the problem this
 *    platform exists to solve, but we hold a state and not a coordinate -- see
 *    tailor_profiles -- so "near me" is an indexed equality that narrows the
 *    list, not a term that quietly reshuffles it. A boost would also be
 *    meaningless on the many searches that already specify a state.
 *
 * 3. A NEWCOMER ALLOWANCE. Without it a new tailor is last forever and can
 *    never earn the reviews that would move her, which makes the directory a
 *    closed shop and the subscription worthless to exactly the people most
 *    likely to pay for it. A flat bonus while she has no completed orders,
 *    which ends the moment she has one -- and sized to stay below what a
 *    single genuine review earns, so an empty profile never outranks a
 *    tailor a real customer praised.
 */
class TailorRanking
{
    /**
     * How many notional reviews at the prior mean each tailor starts with.
     *
     * The weight of the prior. Higher means a rating moves more slowly and is
     * harder to fake; lower means real differences show sooner. Five is
     * enough that a single manufactured review moves a tailor by about a
     * fifth of a star.
     */
    private const PRIOR_WEIGHT = 5;

    /**
     * Where a tailor we know nothing about is assumed to sit: the middle of
     * the scale.
     *
     * NOT the platform's own average, which is the textbook choice and is
     * wrong here. In a population where everybody is rated 4.8, a mean-based
     * prior assumes a brand-new tailor is 4.8 too -- so her first
     * manufactured five-star review leaves her at 4.8 and she outranks a
     * house with forty real reviews averaging 4.77. That is the exact
     * failure this formula exists to prevent, and it showed up the first
     * time the ranking was tested.
     *
     * The middle of the scale is the honest assumption about an unknown, and
     * it is conservative in the direction that matters.
     */
    private const PRIOR_MEAN = 3.5;

    /**
     * @return LengthAwarePaginator<int, TailorProfile>
     */
    /**
     * Every tailor who may appear in the directory, with nothing ranked yet.
     *
     * Its own method so the listing and the directory's headline numbers
     * ("50 tailors, 18 states, 87 garments being made") are counted over the
     * SAME population -- a total that included lapsed or suspended tailors
     * would advertise people a visitor cannot find.
     *
     * @return Builder<TailorProfile>
     */
    public function listed(): Builder
    {
        return TailorProfile::query()
            ->whereHas('user', fn (Builder $user) => $user
                ->where('role', User::ROLE_TAILOR)
                ->where('status', User::STATUS_ACTIVE))
            /*
             * The subscription gate, here and nowhere else. A lapse hides a
             * tailor from the directory and changes nothing else -- not her
             * orders, not her measurements, not her money, not her own page.
             *
             * Compared against TIMESTAMPS, never against subscriptions.status.
             * The label is only as fresh as the last thing that recomputed it,
             * and on a host with no worker that can be never -- so a tailor
             * whose term ended last night is out of the directory this
             * morning whether or not anything has run.
             */
            ->when($this->requiresSubscription(), fn (Builder $q) => $q
                ->whereHas('user.subscription', fn (Builder $s) => $s->covering()));
    }

    public function search(?string $state, ?string $term, int $perPage = 12): LengthAwarePaginator
    {
        $query = $this->listed()
            ->with('user')
            ->withCount(['reviewsReceived as review_count' => fn ($q) => $q
                ->where('status', Review::PUBLISHED)
                ->where('direction', Review::CUSTOMER_TO_TAILOR)])
            /*
             * Garments on her table right now: being made, or finished and
             * waiting to be collected. The directory shows it as a live dot --
             * a busy tailor is evidence, and a quiet one is "taking new work".
             * A count, never the orders: nothing about whose they are.
             */
            ->withCount(['liveOrders as live_orders_count']);

        if ($term) {
            // Business name only. A directory that searched bios would rank on
            // whoever stuffed the most garment names into a paragraph.
            $query->where('business_name', 'like', '%'.$term.'%');
        }

        if ($state) {
            $query->where('state', $state);
        }

        /*
         * The score, in SQL so it can be ordered and paginated.
         *
         *   (prior_weight * prior_mean + sum_of_ratings)
         *   -------------------------------------------   + newcomer bonus
         *         (prior_weight + review_count)
         *
         * avg_rating * review_count reconstitutes the sum without a join,
         * which is what the denormalised column on tailor_profiles is for.
         */
        // addSelect, not select: a bare select() replaces the column list and
        // silently discards the withCount subquery above, which then reads
        // back as null rather than a number.
        $query->addSelect('tailor_profiles.*')->orderByRaw(
            '((? * ?) + (avg_rating * (
                select count(*) from reviews
                 where reviews.subject_id = tailor_profiles.user_id
                   and reviews.status = ?
                   and reviews.direction = ?
            ))) / (? + (
                select count(*) from reviews
                 where reviews.subject_id = tailor_profiles.user_id
                   and reviews.status = ?
                   and reviews.direction = ?
            )) + (case when orders_completed = 0 then ? else 0 end) desc',
            [
                self::PRIOR_WEIGHT, self::PRIOR_MEAN,
                Review::PUBLISHED, Review::CUSTOMER_TO_TAILOR,
                self::PRIOR_WEIGHT,
                Review::PUBLISHED, Review::CUSTOMER_TO_TAILOR,
                $this->newcomerBonus(),
            ],
        )->orderBy('orders_completed', 'desc')->orderBy('id');

        return $query->paginate($perPage)->withQueryString();
    }

    /**
     * Whether the listing is something she has to buy.
     *
     * A setting because of the cold start: a directory that requires payment
     * is empty on launch day, and nobody pays to join an empty platform.
     */
    private function requiresSubscription(): bool
    {
        return (bool) PlatformSetting::get(PlatformSetting::DIRECTORY_REQUIRES_SUBSCRIPTION, true);
    }

    /**
     * Enough to lift a new tailor clear of a badly-rated one, and not enough
     * to beat a single real five-star review. See the setting for the
     * arithmetic; the test pins the ordering.
     */
    private function newcomerBonus(): float
    {
        return (float) PlatformSetting::get(PlatformSetting::DIRECTORY_NEWCOMER_BONUS, 0.2);
    }
}
