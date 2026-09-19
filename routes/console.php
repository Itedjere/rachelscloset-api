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
