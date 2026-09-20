<?php

namespace App\Console\Commands;

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Notifications\SubscriptionExpiring;
use App\Notifications\SubscriptionLapsed;
use Illuminate\Console\Command;

/**
 * Tells tailors their listing is running out.
 *
 * A COURTESY, NOT AN INVARIANT, and the distinction is the whole point of
 * prepaid terms. Expiry is a computed fact: a term ends when its timestamp
 * passes, whether or not this ever runs. If the cron dies, tailors still
 * lapse correctly and still renew correctly -- only the reminder goes quiet,
 * and the dashboard banner says the same thing to anybody who signs in.
 *
 * Compare `payouts:release-due`, which is the same shape for the same reason:
 * a stopped sweep costs a tailor a tap, not her money.
 */
class RemindExpiringSubscriptions extends Command
{
    protected $signature = 'subscriptions:remind {--dry-run : List who would be told, and tell nobody}';

    protected $description = 'Remind tailors whose listing is about to end, and tell those whose has';

    /**
     * Seven days, three, then one.
     *
     * Descending, because the code sends the smallest reminder still owed --
     * so a cron that did not run for four days sends "one day left" rather
     * than a stale "seven days left" followed by three more.
     */
    private const MILESTONES = [7, 3, 1];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $sent = 0;

        $sent += $this->remind($dry);
        $sent += $this->announceLapses($dry);

        /*
         * The heartbeat the admin dashboard reads. Not on a dry run: that
         * proves the command can be invoked, not that the schedule is alive.
         */
        if (! $dry) {
            PlatformSetting::set(PlatformSetting::SUBSCRIPTIONS_REMINDED_AT, now()->toIso8601String());
        }

        $this->info($dry ? "{$sent} would be told. Nothing sent." : "{$sent} told.");

        return self::SUCCESS;
    }

    private function remind(bool $dry): int
    {
        $due = Subscription::query()
            ->with('user')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '>', now())
            ->where('current_period_end', '<=', now()->addDays(max(self::MILESTONES)))
            ->get();

        $sent = 0;

        foreach ($due as $subscription) {
            $left = (int) ceil(now()->diffInDays($subscription->current_period_end, false));

            // The smallest milestone she has reached.
            $milestone = collect(self::MILESTONES)->filter(fn (int $m) => $left <= $m)->min();

            if ($milestone === null) {
                continue;
            }

            /*
             * Already told at this milestone or a nearer one. Guards against a
             * cron firing twice in a day, which is the ordinary way these
             * things are misconfigured.
             */
            if ($subscription->last_reminder_days !== null
                && $subscription->last_reminder_days <= $milestone) {
                continue;
            }

            $this->line("  {$subscription->user?->name}: {$left} days left");

            if (! $dry) {
                $subscription->user?->notify(new SubscriptionExpiring($subscription, $milestone));
                $subscription->forceFill(['last_reminder_days' => $milestone])->save();
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * And the ones that have just ended.
     *
     * Found by the timestamp having passed while the label still says
     * otherwise -- which is also what keeps this from telling the same person
     * every night: syncStatus() writes `lapsed` and she stops matching.
     */
    private function announceLapses(bool $dry): int
    {
        $lapsed = Subscription::query()
            ->with('user')
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', now())
            ->where('status', '!=', Subscription::LAPSED)
            ->get()
            // Grace is not a lapse. She is still listed.
            ->reject(fn (Subscription $s) => $s->covers());

        foreach ($lapsed as $subscription) {
            $this->line("  {$subscription->user?->name}: listing ended");

            if (! $dry) {
                $subscription->user?->notify(new SubscriptionLapsed($subscription));
                $subscription->syncStatus();
            }
        }

        return $lapsed->count();
    }
}
