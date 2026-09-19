<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\OrderStep;
use App\Models\StepTemplate;
use Illuminate\Support\Facades\DB;

/**
 * Copies the tailor's arrangement onto an order.
 *
 * Done at creation, not at payment, so the customer can see what stages her
 * garment will pass through while she is deciding whether to pay for it. That
 * is the whole proposition -- she should not have to hand over money to find
 * out what happens next.
 *
 * Everything is copied, not referenced. From this moment the order carries its
 * own words and its own recordings, and the library can change underneath it
 * without touching what she was told.
 */
class AssembleOrderSteps
{
    public function __construct(private readonly RecalculateOrderProgress $progress) {}

    public function handle(Order $order): int
    {
        return DB::transaction(function () use ($order) {
            // Assembling twice would duplicate the checklist. An order gets
            // its steps once.
            if ($order->steps()->exists()) {
                return 0;
            }

            $template = StepTemplate::forTailor($order->garmentType, $order->tailor);

            if (! $template) {
                return 0;
            }

            $position = 0;

            foreach ($template->items()->with('step')->get() as $item) {
                $step = $item->step;

                if (! $step) {
                    continue;
                }

                /*
                 * A step retired between the arrangement being saved and this
                 * order being opened is still copied. She arranged her work
                 * that way; an admin tidying the library is not a reason to
                 * silently drop a stage from a garment being made now.
                 */
                OrderStep::create([
                    'order_id' => $order->id,
                    'production_step_id' => $step->id,
                    'position' => ++$position,
                    'label' => $step->label,
                    'instructions' => $step->instructions,
                    'voice_note_url' => $step->voice_note_url,
                ]);
            }

            /*
             * The counters live on the order, and nothing else writes them
             * until the first stage is ticked. Without this an order would
             * read "0 of 0" on the list from the moment it was opened until
             * the tailor touched it -- which is precisely the stretch where
             * the customer is wondering whether anything is happening.
             *
             * Recomputed rather than set to $position, so there is still only
             * one piece of code that decides what these numbers are.
             */
            $this->progress->handle($order);

            return $position;
        });
    }
}
