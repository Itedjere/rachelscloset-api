<?php

namespace App\Services\Reviews;

use App\Models\Order;
use App\Models\Review;
use App\Models\User;

/**
 * Recomputes a tailor's public numbers from scratch.
 *
 * FROM SCRATCH, never adjusted -- the same rule as an order's step counters
 * and for the same reason. A running average that is nudged on each new
 * review drifts the first time one is deleted, held, or released by an admin,
 * and the drift is invisible: nobody notices that 4.6 should have been 4.4.
 *
 * Only PUBLISHED reviews count. A held review is not yet a fact about
 * anybody, and letting it move the average would defeat the gate entirely --
 * the rating would rise the moment the review was written and the hold would
 * be decoration.
 */
class RecalculateTailorRating
{
    public function handle(User $tailor): void
    {
        $profile = $tailor->tailorProfile;

        if (! $profile) {
            return;
        }

        $reviews = Review::query()
            ->published()
            ->where('subject_id', $tailor->id)
            ->where('direction', Review::CUSTOMER_TO_TAILOR);

        /*
         * orders_completed counts finished work, not reviews. A customer who
         * never gets round to reviewing has still had a garment made, and a
         * directory that said otherwise would understate every tailor whose
         * customers are simply busy.
         */
        $completed = Order::query()
            ->where('tailor_id', $tailor->id)
            ->where('status', Order::COMPLETED)
            ->count();

        $profile->forceFill([
            'avg_rating' => round((float) $reviews->clone()->avg('rating'), 2),
            'orders_completed' => $completed,
        ])->save();
    }
}
