<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| Housekeeping.
|
| Runs in the small hours Lagos time, when nothing else is happening. Nothing
| depends on this having run -- if cron stops, notifications simply accumulate,
| and NOTIFICATIONS_PRUNED_AT going stale is what makes that noticeable rather
| than a full disk.
*/
Schedule::command('notifications:prune')->dailyAt('03:15');

/*
| Escrow whose waiting period has run out.
|
| A courtesy: a tailor can release her own money the moment it is due, so a
| cron that has quietly stopped costs her a tap rather than stranding it.
*/
Schedule::command('payouts:release-due')->dailyAt('06:00');
