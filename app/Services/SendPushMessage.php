<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;
use Throwable;

/**
 * Delivers an alert to every device a person has subscribed.
 *
 * Sent during the request rather than from a queue, for the reason the whole
 * architecture is shaped around: a worker needs a long-running process the
 * shared host cannot run, and a cron-driven one quietly stops. A person has one
 * or two devices, so this is one or two short HTTPS calls. If it ever becomes
 * the slow part of ticking off a step, this is the piece to move.
 *
 * A subscription the push service reports as gone is deleted. Browsers expire
 * them routinely -- cleared site data, a reinstalled browser -- and keeping
 * dead rows means every future send pays for them again.
 */
class SendPushMessage
{
    /**
     * Whether push is set up at all.
     *
     * Without VAPID keys this does nothing and says nothing. That is the point:
     * push is a courtesy on top of the in-app record, so an environment that
     * has not generated keys yet must still be able to run the whole platform
     * rather than throwing on every notification.
     */
    public function configured(): bool
    {
        return filled(config('services.push.public_key'))
            && filled(config('services.push.private_key'));
    }

    public function toUser(User $user, array $payload): void
    {
        if (! $this->configured()) {
            return;
        }

        $subscriptions = PushSubscription::query()->where('user_id', $user->id)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => config('services.push.subject'),
                    'publicKey' => config('services.push.public_key'),
                    'privateKey' => config('services.push.private_key'),
                ],
            ]);

            // Push services cap the payload; four kilobytes is the usual limit
            // and this is a title and a sentence, so it is never close.
            $body = json_encode($payload);

            foreach ($subscriptions as $subscription) {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                    ]),
                    $body,
                );
            }

            foreach ($webPush->flush() as $report) {
                $this->handleReport($report);
            }
        } catch (Throwable $exception) {
            // An alert that fails is a missed convenience, never a lost record --
            // the in-app notification is already written by the time this runs.
            Log::warning('Push notification failed', [
                'user_id' => $user->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function handleReport(object $report): void
    {
        $endpoint = $report->getRequest()->getUri()->__toString();

        if ($report->isSuccess()) {
            PushSubscription::query()
                ->where('endpoint', $endpoint)
                ->update(['last_used_at' => now()]);

            return;
        }

        // 404 or 410: the browser has thrown the subscription away.
        if ($report->isSubscriptionExpired()) {
            PushSubscription::query()->where('endpoint', $endpoint)->delete();

            return;
        }

        Log::warning('Push notification rejected', [
            'endpoint' => $endpoint,
            'reason' => $report->getReason(),
        ]);
    }
}
