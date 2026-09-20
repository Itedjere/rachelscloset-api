<?php

namespace App\Notifications;

use App\Models\User;

/**
 * Your account works again.
 *
 * Also ACCOUNT, and for the mirror of the same reason: somebody who stopped
 * using the platform because she could not get in has no way of discovering
 * that she can, unless she is told.
 */
class AccountReinstated extends ClosetNotification
{
    public function __construct(private readonly User $user) {}

    public function type(): string
    {
        return 'account_reinstated';
    }

    public function subject(object $notifiable): string
    {
        return 'Your account is back';
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => 'You can sign in and carry on as before.',
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/';
    }
}
