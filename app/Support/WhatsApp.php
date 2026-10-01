<?php

namespace App\Support;

use App\Rules\NigerianPhone;

/**
 * A wa.me link: WhatsApp from the sender's OWN app, which costs nobody
 * anything -- not the Business API.
 *
 * One place for this because every hand-written copy had the same bug. Phones
 * are stored the way people write them here, `08031234567`, and wa.me needs
 * the international number with no leading zero, `2348031234567`. Stripping
 * non-digits from the stored form gave `wa.me/08031234567`, which opens a chat
 * with nobody -- on a tailor's public page, that was the button a customer
 * taps after scanning her printed card.
 */
class WhatsApp
{
    /** A chat with this number, optionally with a message ready to send. */
    public static function to(string $phone, ?string $text = null): string
    {
        return 'https://wa.me/'.self::international($phone)
            .($text !== null ? '?text='.rawurlencode($text) : '');
    }

    /** No recipient: she picks who to send it to. For invitations. */
    public static function share(string $text): string
    {
        return 'https://wa.me/?text='.rawurlencode($text);
    }

    /**
     * `2348031234567` from however the number was written.
     *
     * A tailor's WhatsApp number is typed free-form on her profile and was
     * never normalised, so this accepts anything NigerianPhone does and falls
     * back to bare digits rather than dropping the link.
     */
    public static function international(string $phone): string
    {
        $local = NigerianPhone::normalise($phone);

        return $local !== null
            ? '234'.substr($local, 1)
            : preg_replace('/\D/', '', $phone);
    }
}
