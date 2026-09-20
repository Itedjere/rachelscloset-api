<?php

namespace App\Services\Subscriptions;

use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTerm;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turning money, or an admin's goodwill, into days.
 *
 * The one place a term is ever created. Two rules live here and nowhere else:
 *
 * 1. TERMS STACK. A new term begins when the current one ends, not today. So
 *    a tailor on a monthly who buys a yearly loses nothing, upgrading is just
 *    "buy a yearly", and there is no proration to get wrong -- which is the
 *    main thing prepaid terms buy over recurring billing.
 *
 * 2. ONE TERM PER PAYMENT. The unique index on subscription_terms.payment_id
 *    is the real guard; this checks first so the ordinary replay is a quiet
 *    no-op rather than an exception in a log nobody reads. A webhook replayed
 *    by Flutterwave and a customer refreshing the return page are both
 *    routine, not exceptional.
 */
class StartTerm
{
    /** @return array{days: int, price: string} */
    public function plan(string $plan): array
    {
        return $plan === Subscription::YEARLY
            ? [
                'days' => (int) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_YEARLY_DAYS, 365),
                'price' => (string) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_PRICE_YEARLY, '20000'),
            ]
            : [
                'days' => (int) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_MONTHLY_DAYS, 30),
                'price' => (string) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_PRICE_MONTHLY, '2000'),
            ];
    }

    /** A term bought with a payment. Idempotent on that payment. */
    public function fromPayment(Payment $payment): ?SubscriptionTerm
    {
        if ($payment->purpose !== Payment::PURPOSE_SUBSCRIPTION) {
            return null;
        }

        $subscription = Subscription::query()->whereKey($payment->subscription_id)->first();

        if (! $subscription) {
            return null;
        }

        $plan = $payment->plan ?? Subscription::MONTHLY;

        return $this->add(
            $subscription,
            $plan,
            /*
             * The days come from the settings AS THEY ARE NOW, not as they
             * were when she started paying. That is the right way round: the
             * alternative is a tailor who opened the page in the morning
             * getting yesterday's term length tonight. The PRICE is not
             * re-read -- it is whatever she actually paid.
             */
            $this->plan($plan)['days'],
            (string) $payment->amount,
            $payment,
        );
    }

    /** A term an admin granted. No payment, so no idempotency key. */
    public function grant(User $tailor, string $plan, ?User $by = null, ?string $note = null): SubscriptionTerm
    {
        $subscription = Subscription::forTailor($tailor);

        return $this->add(
            $subscription,
            $plan,
            $this->plan($plan)['days'],
            '0.00',
            null,
            $by,
            $note,
        );
    }

    private function add(
        Subscription $subscription,
        string $plan,
        int $days,
        string $amount,
        ?Payment $payment = null,
        ?User $by = null,
        ?string $note = null,
    ): ?SubscriptionTerm {
        return DB::transaction(function () use ($subscription, $plan, $days, $amount, $payment, $by, $note) {
            /** @var Subscription $subscription */
            $subscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->first();

            if ($payment && SubscriptionTerm::query()->where('payment_id', $payment->id)->exists()) {
                // Already minted. The second arrival changes nothing.
                return null;
            }

            /*
             * Stacking, in one line: start when the current period ends, or
             * now if it already has. Grace is deliberately not used here --
             * the courtesy window is about staying listed, not about days she
             * has paid for.
             */
            $startsAt = $subscription->current_period_end?->isFuture()
                ? $subscription->current_period_end
                : now();

            $endsAt = $startsAt->copy()->addDays($days);

            $term = SubscriptionTerm::create([
                'subscription_id' => $subscription->id,
                'payment_id' => $payment?->id,
                'plan' => $plan,
                'days' => $days,
                'amount' => $amount,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'granted_by' => $by?->id,
                'note' => $note,
            ]);

            $grace = (int) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_GRACE_DAYS, 7);

            $subscription->forceFill([
                'current_period_end' => $endsAt,
                'grace_ends_at' => $endsAt->copy()->addDays($grace),
                // A fresh period gets a fresh set of reminders.
                'last_reminder_days' => null,
            ])->save();

            $subscription->syncStatus();

            return $term;
        });
    }
}
