<?php

namespace App\Services\Reviews;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\Review;

/**
 * The proof gate.
 *
 * It exists for exactly one failure: a tailor inventing orders with a
 * confederate and giving herself five stars. What stops that is not
 * moderation but evidence -- an order that really happened has photographs of
 * the work on it, because Section 10 put a camera on every stage.
 *
 * So a four or five star review of a tailor is held when the order behind it
 * showed almost no work. Everything else publishes immediately.
 *
 * THREE DELIBERATE NARROWINGS, each of which matters:
 *
 * 1. Only four and five stars. A complaint always publishes. Holding a
 *    one-star review in a queue reads as censorship, and a tailor who did no
 *    photo work is precisely the one whose bad reviews most need to be seen.
 *    Gating both directions of the scale would turn an anti-inflation measure
 *    into a reputation filter.
 *
 * 2. Only reviews OF A TAILOR. A tailor rating a customer cannot inflate the
 *    thing the directory ranks on, and customer ratings are not ranked at
 *    all. Gating them would be ceremony.
 *
 * 3. Only orders with enough stages to judge. A two-stage garment with one
 *    photograph is 50% proved and means nothing either way; the ratio is only
 *    information once there are a few stages. Below the floor, publish.
 */
class PublishReview
{
    public function handle(Review $review, Order $order): Review
    {
        if (! $this->gateApplies($review, $order)) {
            $review->publish();

            return $review->fresh();
        }

        $ratio = $this->proofRatio($order);
        $threshold = (float) PlatformSetting::get(PlatformSetting::REVIEW_PROOF_THRESHOLD, 80);

        if ($ratio >= $threshold) {
            /*
             * Snapshotted even when it passes. The ratio is the basis on
             * which the decision was made, and photographs can be added to
             * an order afterwards -- so the number that mattered has to be
             * the number as it stood, not one recomputed later. Same
             * reasoning as an order snapshotting its step labels.
             */
            $review->forceFill(['proof_ratio_snapshot' => (string) $ratio])->save();
            $review->publish();

            return $review->fresh();
        }

        $review->hold((string) $ratio);

        return $review->fresh();
    }

    private function gateApplies(Review $review, Order $order): bool
    {
        if ($review->direction !== Review::CUSTOMER_TO_TAILOR) {
            return false;
        }

        if ($review->rating < Review::GATED_FROM) {
            return false;
        }

        $minSteps = (int) PlatformSetting::get(PlatformSetting::REVIEW_PROOF_MIN_STEPS, 3);

        return $order->steps_completed >= $minSteps;
    }

    /**
     * How much of the finished work was shown, as a percentage.
     *
     * Completed stages are the denominator, not every stage on the order. A
     * stage that was never done cannot be photographed, and counting it
     * against her would punish a tailor for an order that ended early rather
     * than for hiding anything.
     */
    private function proofRatio(Order $order): float
    {
        if ($order->steps_completed < 1) {
            return 0.0;
        }

        /*
         * Capped at 100. A tailor can photograph a stage before she ticks it
         * -- she takes the picture as she finishes the sleeve and taps the
         * circle later -- so mid-order there can be more photographed stages
         * than completed ones, and the raw division reads as 150%.
         *
         * By review time the order has been collected and the two are equal,
         * so this changes no decision. It exists because a percentage over
         * 100 shown to an admin on the held-review screen reads as a bug,
         * and because the next person to use this number should not have to
         * rediscover the case.
         */
        $ratio = ($order->steps_with_photo / $order->steps_completed) * 100;

        return round(min($ratio, 100), 2);
    }
}
