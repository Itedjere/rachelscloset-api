<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Your account has been suspended.
 *
 * ACCOUNT category, which cannot be switched off -- see
 * NotificationCategories, which says so in as many words: being told you have
 * been suspended is not marketing, and somebody who cannot use the platform
 * has to be able to find out why.
 *
 * The reason is included when an admin gave one. A suspension with no
 * explanation is the kind of thing that turns into a phone call, which is
 * more work for everybody than typing a sentence was.
 */
class AccountSuspended extends ClosetNotification
{
    public function __construct(
        private readonly User $user,
        private readonly ?string $reason = null,
    ) {}

    public function type(): string
    {
        return 'account_suspended';
    }

    public function subject(object $notifiable): string
    {
        return 'Your account has been paused';
    }

    public function payload(object $notifiable): array
    {
        $until = $this->user->suspended_until;

        return [
            'message' => $this->reason
                ?: 'Your account has been paused. Get in touch if you think this is a mistake.',
            // Said plainly: an end date is the difference between a pause and
            // being thrown off, and only one of those is what happened.
            'until' => $until,
            'until_label' => $until?->format('j F Y'),
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/';
    }
}
