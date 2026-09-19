<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStepPhoto;
use App\Models\PlatformSetting;
use App\Notifications\OrderReady;

/**
 * Recomputes an order's progress counters from scratch.
 *
 * FROM SCRATCH, never adjusted. The same rule as a tailor's average rating: a
 * counter that is incremented and decremented drifts the first time something
 * is deleted, un-ticked or assembled twice, and the drift is invisible until
 * somebody notices a garment that is "7 of 6 done".
 *
 * Also the one place an order becomes ready. Ticking the last stage is what
 * finishes a garment -- a separate "mark ready" button after ticking "Ready to
 * collect" would be asking twice.
 */
class RecalculateOrderProgress
{
    public function handle(Order $order): Order
    {
        $steps = $order->steps()->get();

        $total = $steps->count();
        $completed = $steps->whereNotNull('completed_at')->count();

        /*
         * Stages carrying at least one photograph -- stages, not photographs.
         * Three pictures of the same sleeve is one stage proved, and Section
         * 13's review gate asks how much of the work was shown, not how many
         * times the shutter went.
         *
         * One indexed query rather than a join, which is what the
         * denormalised order_id on order_step_photos is for.
         */
        $withPhoto = OrderStepPhoto::query()
            ->where('order_id', $order->id)
            ->distinct()
            ->count('order_step_id');

        $order->forceFill([
            'steps_total' => $total,
            'steps_completed' => $completed,
            'steps_with_photo' => $withPhoto,
        ])->save();

        if ($total > 0 && $completed === $total && $order->status === Order::IN_PROGRESS) {
            $this->markReady($order);
        }

        return $order->fresh();
    }

    /**
     * Finished and waiting.
     *
     * The collection deadline starts here, which is what turns "the customer
     * never came back" from something the tailor absorbs into something the
     * platform can act on.
     */
    private function markReady(Order $order): void
    {
        $days = (int) PlatformSetting::get(PlatformSetting::COLLECTION_DEADLINE_DAYS, 14);

        $order->forceFill([
            'status' => Order::READY,
            'ready_at' => now(),
            'collection_deadline' => now()->addDays($days)->toDateString(),
        ])->save();

        $order->customer->notify(new OrderReady($order->fresh()->load('tailor', 'garmentType')));
    }
}
