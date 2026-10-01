<?php

namespace App\Notifications;

/**
 * Your PIN was changed.
 *
 * ACCOUNT category, which cannot be switched off. This is the one message
 * that tells somebody their account was taken over: if she did not do this,
 * the notification is how she finds out, and a setting able to silence it
 * would silence exactly the warning that matters.
 *
 * Deliberately says nothing about who issued the code or how. A person
 * reading this on a lock screen needs one fact and a way to act on it.
 */
class PinWasReset extends ClosetNotification
{
    public function type(): string
    {
        return 'pin_reset';
    }

    public function subject(object $notifiable): string
    {
        return 'Your secret number was changed';
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => 'If you did not do this, call Rachels Closet straight away.',
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/profile';
    }
}
