<?php

namespace App\Notifications;

use App\Models\Subscription;

/**
 * Your listing runs out soon.
 *
 * A COURTESY, NOT AN INVARIANT. Nothing depends on this arriving: the term
 * ends when it ends, expiry is a computed fact, and a dead cron costs a
 * missed reminder rather than a wrong answer. The dashboard banner says the
 * same thing to anybody who signs in, which is the backstop.
 */
class SubscriptionExpiring extends ClosetNotification
{
    public function __construct(
        private readonly Subscription $subscription,
        private readonly int $days,
    ) {}

    public function type(): string
    {
        return 'subscription_expiring';
    }

    public function subject(object $notifiable): string
    {
        return $this->days === 1
            ? 'Your listing ends tomorrow'
            : "Your listing ends in {$this->days} days";
    }

    public function payload(object $notifiable): array
    {
        return [
            // Says what happens, not what to do: the consequence is mild and
            // overstating it would be a scare.
            'message' => $this->days === 1
                ? 'Renew to stay in the Fashion House. Your orders are not affected.'
                : "Your Fashion House listing ends in {$this->days} days. Renew to stay listed.",
            'days' => $this->days,
            'ends_at' => $this->subscription->current_period_end,
        ];
    }

    public function url(object $notifiable): ?string
    {
        return '/subscription';
    }
}
