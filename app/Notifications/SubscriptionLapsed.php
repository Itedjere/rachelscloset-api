<?php

namespace App\Notifications;

use App\Models\Subscription;

/**
 * Your listing has ended.
 *
 * Deliberately mild, because the consequence is mild: §3 says a lapse hides
 * her from the directory and nothing else. Her orders, her money, her
 * measurements and the page a printed card points at all keep working, and
 * saying otherwise would be a lie told to sell a renewal.
 */
class SubscriptionLapsed extends ClosetNotification
{
    public function __construct(private readonly Subscription $subscription) {}

    public function type(): string
    {
        return 'subscription_lapsed';
    }

    public function subject(object $notifiable): string
    {
        return 'Your listing has ended';
    }

    public function payload(object $notifiable): array
    {
        return [
            'message' => 'Customers can no longer find you in the Fashion House list. Your orders and your money are not affected -- pay again any time to come back.',
            'ended_at' => $this->subscription->current_period_end,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/subscription';
    }
}
