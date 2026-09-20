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

/*
| Listings that are about to run out, and ones that just have.
|
| The same shape as the two above, for the same reason: expiry is a computed
| fact, so nothing here is load-bearing. If cron stops, tailors still lapse and
| still renew correctly -- only the reminder goes quiet, and the dashboard
| banner tells anybody who signs in.
|
| Morning rather than the small hours: this one is read by a person who is
| being asked to pay, and a 3am notification is a notification swiped away.
*/
Schedule::command('subscriptions:remind')->dailyAt('09:00');
