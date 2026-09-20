<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Notifications\CollectionReminder;
use Illuminate\Console\Command;

/**
 * Nudges customers whose finished clothes are still at the tailor.
 *
 * The brief's second problem -- "customer won't collect or can't pay" -- is
 * answered by the `ready` state, the collection deadline, and reminders. The
 * first two have existed since Section 5 for the sake of the third, which
 * until now nothing sent.
 *
 * A COURTESY, like the other two scheduled commands. Nothing here is an
 * invariant: the deadline is a date on a row and the admin dashboard counts
 * overdue orders by comparing it, whether or not this ever runs. A dead cron
 * costs a nudge, not a wrong answer -- the same shape as `payouts:release-due`
 * and `subscriptions:remind`, and for the same reason.
 */
class RemindUncollectedOrders extends Command
{
    protected $signature = 'orders:remind-collection {--dry-run : List who would be told, and tell nobody}';

    protected $description = 'Remind customers to collect finished garments, and tell the tailor when one goes overdue';

    /**
     * Three days out, then one.
     *
     * Descending, and the command sends the smallest milestone still owed, so
     * a cron that did not run for four days sends the nearest warning rather
     * than a stale "three days" followed by the rest in a burst.
     *
     * Overdue is recorded as zero but is NOT in this list, because the
     * deadline day itself is not overdue -- `$left` reaches 0 at midnight on
     * the day she may still collect, and treating that as a milestone told
     * her the garment "was due" on the very day it was due.
     *
     * ZERO IS TERMINAL AND DELIBERATELY SO. An uncollected garment never
     * resolves itself the way a lapsed subscription does, so a command with
     * no last milestone would notify the same person every night for ever --
     * which is how somebody learns to ignore all of them, including the ones
     * about her next order. It is said once, and after that the order sits on
     * the admin dashboard where a person can ring.
     */
    private const MILESTONES = [3, 1];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        $waiting = Order::query()
            ->with(['customer', 'tailor', 'garmentType'])
            ->where('status', Order::READY)
            ->whereNotNull('collection_deadline')
            ->get();

        foreach ($waiting as $order) {
            $left = (int) ceil(now()->startOfDay()->diffInDays($order->collection_deadline, false));

            // The smallest milestone this order has reached. Anything still
            // further out than the largest is simply not due a nudge yet.
            $milestone = $left < 0
                ? 0
                : collect(self::MILESTONES)->filter(fn (int $m) => $left <= $m)->min();

            if ($milestone === null) {
                continue;
            }

            // Already told at this milestone or a nearer one.
            if ($order->collection_reminded_days !== null
                && $order->collection_reminded_days <= $milestone) {
                continue;
            }

            $this->line("  {$order->reference}: {$left} days left");

            if (! $dry) {
                $order->customer?->notify(new CollectionReminder($order, $milestone));

                /*
                 * Only at the end, and only to her. Three nudges into the
                 * tailor's phone about an order she can see on her own list
                 * would be noise; the moment it is genuinely overdue is when
                 * she needs the customer's number in front of her.
                 */
                if ($milestone === 0) {
                    $order->tailor?->notify(new CollectionReminder($order, $milestone));
                }

                $order->forceFill(['collection_reminded_days' => $milestone])->save();
            }

            $sent++;
        }

        /*
         * The heartbeat the admin dashboard reads. Not on a dry run: that
         * proves the command can be invoked, not that the schedule is alive.
         */
        if (! $dry) {
            PlatformSetting::set(PlatformSetting::COLLECTION_REMINDED_AT, now()->toIso8601String());
        }

        $this->info($dry ? "{$sent} would be reminded. Nothing sent." : "{$sent} reminded.");

        return self::SUCCESS;
    }
}
