<?php

namespace App\Support;

/**
 * How long somebody has to wait, said the way a person would say it.
 *
 * "Too many attempts" on its own is a dead end: it does not say whether to try
 * again in ten seconds or come back tomorrow, so people either sit refreshing or
 * give up and contact support. The number is already known — it just was never
 * passed on.
 */
class RetryAfter
{
    public static function describe(int $seconds): string
    {
        if ($seconds <= 1) {
            return 'a moment';
        }

        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        // Rounded up, because saying a minute when there are 90 seconds left
        // only earns a second refusal.
        $minutes = (int) ceil($seconds / 60);

        if ($minutes < 60) {
            return $minutes === 1 ? 'a minute' : "{$minutes} minutes";
        }

        $hours = (int) ceil($minutes / 60);

        return $hours === 1 ? 'an hour' : "{$hours} hours";
    }
}
