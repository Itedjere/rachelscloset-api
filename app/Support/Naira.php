<?php

namespace App\Support;

/**
 * Money as a person reads it in a message: "₦20,000", and kobo only when there
 * are any ("₦20,000.50").
 *
 * The app has always shown whole naira without ".00"; the notifications wrote
 * every amount with it, so the same ₦20,000 read two ways depending on where
 * she saw it. One formatter, used by every message that names an amount.
 */
class Naira
{
    public static function format(string|int|float $amount): string
    {
        $value = (float) $amount;
        $whole = fmod($value, 1.0) === 0.0;

        return '₦'.number_format($value, $whole ? 0 : 2);
    }
}
