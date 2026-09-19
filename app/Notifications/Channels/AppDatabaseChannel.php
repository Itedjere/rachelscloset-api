<?php

namespace App\Notifications\Channels;

use App\Models\Notification as AppNotification;
use Illuminate\Notifications\Notification;

/**
 * Writes to the plain `notifications` table rather than Laravel's polymorphic
 * one, which nothing here needs: every notification is addressed to one user.
 *
 * Going through a channel rather than writing the row directly means each event
 * still gets Laravel's notification plumbing -- and the other channels
 * alongside -- without a second table shape to reconcile.
 */
class AppDatabaseChannel
{
    public function send(object $notifiable, Notification $notification): void
    {
        /** @var array{type: string, payload: array} $data */
        $data = $notification->toDatabase($notifiable);

        AppNotification::create([
            'user_id' => $notifiable->getKey(),
            'type' => $data['type'],
            'payload' => $data['payload'],
        ]);
    }
}
