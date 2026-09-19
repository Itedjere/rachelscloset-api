<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The six digits somebody signs in with.
 *
 * A PIN rather than a password because many tailors here read poorly, and a
 * numeric keypad asks nothing of them that a text field does: no spelling, no
 * case, no symbols. It is still hashed in the `password` column with bcrypt —
 * the PIN *is* the password, only entered differently.
 *
 * Six digits, not four. Four is a million times easier to guess than it sounds:
 * ten thousand combinations against a username that is a phone number, and phone
 * numbers are enumerable. Six gets that to a million, at the cost of two more
 * taps. That trade is worth taking every time.
 *
 * Length alone is not enough, though, because people do not choose uniformly.
 * The rejections below cover what an attacker would try in their first hundred
 * guesses: repeats, runs, and -- the one people reach for most -- digits out of
 * their own phone number, which is the very thing already on screen next to it.
 */
class Pin implements ValidationRule
{
    public const LENGTH = 6;

    public function __construct(private readonly ?string $phone = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $pin = is_string($value) ? $value : '';

        if (preg_match('/^\d{'.self::LENGTH.'}$/', $pin) !== 1) {
            $fail('Your PIN must be exactly '.self::LENGTH.' numbers.');

            return;
        }

        if (self::isRepeated($pin) || self::isRun($pin)) {
            $fail('That PIN is too easy to guess. Avoid repeats like 111111 and runs like 123456.');

            return;
        }

        if ($this->phone !== null && self::appearsIn($pin, $this->phone)) {
            $fail('That PIN is part of your phone number, which anybody can see. Choose different numbers.');
        }
    }

    /** 000000, 111111 — one digit over and over. */
    private static function isRepeated(string $pin): bool
    {
        return count(array_unique(str_split($pin))) === 1;
    }

    /** 123456 and 654321, and anything else that counts evenly up or down. */
    private static function isRun(string $pin): bool
    {
        $step = null;

        for ($i = 1; $i < strlen($pin); $i++) {
            $difference = (int) $pin[$i] - (int) $pin[$i - 1];

            if ($step !== null && $difference !== $step) {
                return false;
            }

            $step = $difference;
        }

        return $step === 1 || $step === -1;
    }

    /**
     * Whether the PIN is simply a slice of the person's own number. Compared
     * against the normalised form, so it catches the number however it was
     * typed, and falls back to the raw digits when it will not normalise.
     */
    private static function appearsIn(string $pin, string $phone): bool
    {
        $digits = NigerianPhone::normalise($phone) ?? preg_replace('/\D/', '', $phone);

        return is_string($digits) && $digits !== '' && str_contains($digits, $pin);
    }
}
