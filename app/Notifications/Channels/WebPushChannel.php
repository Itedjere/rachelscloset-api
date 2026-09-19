<?php

namespace App\Notifications\Channels;

use App\Notifications\ClosetNotification;
use App\Services\SendPushMessage;
use Illuminate\Notifications\Notification;

/**
 * The alert that reaches a phone's lock screen.
 *
 * This is the channel that matters most here. A tailor ticks a step off in the
 * shop and the customer -- who would otherwise walk back across town to find
 * out -- is told where she stands. Reading is not required: the phone buzzes
 * and shows one short line.
 *
 * Driven from the same description as the in-app entry, so one event tells one
 * story on both. Nothing here is the record -- the in-app notification is
 * already written by the time this runs -- so an alert that fails to deliver
 * costs a convenience, not information.
 */
class WebPushChannel
{
    public function __construct(private readonly SendPushMessage $push) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof ClosetNotification) {
            return;
        }

        $payload = $notification->payload($notifiable);

        $this->push->toUser($notifiable, [
            'title' => $notification->subject($notifiable),
            'body' => $payload['message'] ?? '',
            // Where tapping it should land. The service worker focuses an open
            // tab where it can rather than opening a second one.
            'url' => $notification->url($notifiable),
            'type' => $notification->type(),
        ]);
    }
}
