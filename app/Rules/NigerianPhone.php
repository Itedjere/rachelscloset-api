<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number somebody can actually be reached on.
 *
 * It carries more weight here than on most sites, because on this platform the
 * phone number *is* the username. Many tailors have never had an email address,
 * so there is nothing else to identify an account by -- and nothing else to
 * reach somebody on when an order goes wrong.
 *
 * Accepts the two forms Nigerians actually write: 08031234567, or +2348031234567
 * with or without the plus. Spaces, dashes and brackets are ignored, since
 * people copy numbers out of contact lists that contain them.
 */
class NigerianPhone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Enter a valid Nigerian phone number, like 08031234567.');

            return;
        }

        if (self::normalise($value) === null) {
            $fail('Enter a valid Nigerian phone number, like 08031234567.');
        }
    }

    /**
     * The same number in the one form somebody would dial: 0 followed by ten
     * digits. Stored that way so a search for a number finds it however the
     * person happened to type it, and -- because this is a login credential --
     * so that one number cannot become two accounts by being written two ways.
     *
     * Null when it is not a Nigerian number at all.
     */
    public static function normalise(string $value): ?string
    {
        $digits = preg_replace('/[\s\-().]/', '', trim($value));

        if ($digits === null) {
            return null;
        }

        $digits = ltrim($digits, '+');

        if (str_starts_with($digits, '234')) {
            $digits = '0'.substr($digits, 3);
        }

        // Eleven digits beginning 0, and the second digit is never 0 — that is
        // every Nigerian mobile and landline prefix.
        return preg_match('/^0[1-9]\d{9}$/', $digits) === 1 ? $digits : null;
    }
}
