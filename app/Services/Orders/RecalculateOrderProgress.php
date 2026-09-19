<?php

namespace App\Services\Orders;

use App\Models\Order;
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

        // steps_with_photo is deliberately untouched: Section 10 owns it, and
        // writing a column back to itself reconciles nothing. It also read
        // null on a model that had just been created -- the schema default is
        // on the row, not on the object -- and wrote that straight back.
        $order->forceFill([
            'steps_total' => $total,
            'steps_completed' => $completed,
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
