<?php

namespace App\Notifications;

/**
 * A notification that means nothing, so that push can be proved to work.
 *
 * Not scaffolding to delete later. Web push cannot be verified from a test
 * suite or a desktop browser alone -- it has to be a real phone, with a real
 * service worker, behind HTTPS, and somebody standing next to it saying whether
 * it buzzed. That is true on the day this ships and every time the host,
 * certificate or VAPID keys change afterwards.
 *
 * Its type is deliberately unmapped in NotificationCategories, so it falls
 * under ACCOUNT and cannot be switched off. A test alert that silently obeyed a
 * preference would be the exact thing you were trying to rule out.
 */
class TestAlert extends ClosetNotification
{
    public function __construct(private readonly string $note = 'This is a test.') {}

    public function type(): string
    {
        return 'test_alert';
    }

    public function subject(object $notifiable): string
    {
        return "Rachel's Closet";
    }

    public function payload(object $notifiable): array
    {
        return ['message' => $this->note];
    }

    public function url(object $notifiable): ?string
    {
        return '/notifications';
    }
}
