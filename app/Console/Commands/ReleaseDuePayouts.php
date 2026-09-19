<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Payout;
use App\Services\Payments\ReleasePayout;
use Illuminate\Console\Command;

/**
 * Sends the payouts whose waiting period has run out.
 *
 * A COURTESY, not the mechanism. A tailor can release her own money the moment
 * it is due, so if this stops running she taps a button instead of being
 * stranded -- the same reasoning that makes a suspension lapse when the
 * account is next used rather than on a schedule.
 */
class ReleaseDuePayouts extends Command
{
    protected $signature = 'payouts:release-due {--dry-run : List what would go, and send nothing}';

    protected $description = 'Release escrow on collected orders past their waiting period';

    public function handle(ReleasePayout $release): int
    {
        $candidates = Payout::query()
            ->where('status', Payout::PENDING)
            ->whereHas('order', fn ($q) => $q->whereIn('status', [Order::COLLECTED, Order::COMPLETED]))
            ->with('order')
            ->get()
            // Computed, not queried: the waiting period is a setting and the
            // rule lives in one place on the model.
            ->filter(fn (Payout $p) => $p->order->escrowReleaseDue());

        if ($candidates->isEmpty()) {
            $this->info('Nothing due.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            foreach ($candidates as $payout) {
                $this->line("  {$payout->order->reference}  {$payout->net_amount}");
            }
            $this->comment($candidates->count().' due. Nothing sent.');

            return self::SUCCESS;
        }

        $sent = 0;

        foreach ($candidates as $payout) {
            $result = $release->handle($payout);

            if ($result->isReleased()) {
                $sent++;
            } else {
                $this->warn("  {$payout->order->reference}: {$result->failure_reason}");
            }
        }

        $this->info("Released {$sent} of {$candidates->count()}.");

        return self::SUCCESS;
    }
}
