<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\PlatformSetting;
use Illuminate\Console\Command;

/**
 * Deletes notifications nobody is going to read again.
 *
 * The table only grows, and it grows fastest here of all: the step tracker
 * writes a row for every stage of every garment, so one order is nine
 * notifications rather than one. A year of ordinary trading would leave a table
 * nobody queries but a shared-hosting quota still pays for.
 *
 * Nothing depends on this having run. If cron stops, notifications accumulate
 * and NOTIFICATIONS_PRUNED_AT goes stale on the admin dashboard -- which is the
 * whole point of writing it (CLAUDE.md section 5).
 */
class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune {--dry-run : Count what would go, and delete nothing}';

    protected $description = 'Delete notifications past their retention window';

    public function handle(): int
    {
        $readDays = (int) PlatformSetting::get(PlatformSetting::NOTIFICATION_READ_RETENTION_DAYS, 14);
        $unreadDays = (int) PlatformSetting::get(PlatformSetting::NOTIFICATION_UNREAD_RETENTION_DAYS, 30);

        $read = Notification::query()
            ->whereNotNull('read_at')
            ->where('read_at', '<', now()->subDays($readDays));

        // Measured from when it was sent, not when it was read -- it never was.
        $unread = Notification::query()
            ->whereNull('read_at')
            ->where('created_at', '<', now()->subDays($unreadDays));

        if ($this->option('dry-run')) {
            $this->line("Read, older than {$readDays} days:   ".(clone $read)->count());
            $this->line("Unread, older than {$unreadDays} days: ".(clone $unread)->count());
            $this->comment('Nothing deleted.');

            return self::SUCCESS;
        }

        $removed = $read->delete() + $unread->delete();

        PlatformSetting::set(PlatformSetting::NOTIFICATIONS_PRUNED_AT, now()->toIso8601String());

        $this->info("Removed {$removed} notification".($removed === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }
}
