<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Notifications\OrderPaid;
use App\Services\Subscriptions\StartTerm;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one place a payment is applied.
 *
 * A payment can be confirmed twice -- once by the webhook and once when the
 * payer returns to the site -- in either order, and sometimes simultaneously.
 * Routing both through here means the rules cannot drift apart, and the row is
 * locked so two arrivals cannot both apply it.
 *
 * Money is compared with bccomp on decimal strings throughout. Casting naira
 * to a float to compare it is how a payment of exactly the right amount
 * occasionally reads as a penny short.
 */
class ConfirmPayment
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly StartTerm $terms,
    ) {}

    public function handle(string $reference): ?Payment
    {
        $payment = Payment::query()->where('provider_reference', $reference)->first();

        if (! $payment) {
            // A reference never issued here. Nothing to do, nothing to trust.
            Log::warning('Payment confirmation for an unknown reference', ['reference' => $reference]);

            return null;
        }

        // Never take the browser's word for it: ask the provider directly.
        $verification = $this->gateways->for($payment->provider)->verify($reference);

        return DB::transaction(function () use ($payment, $verification) {
            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();

            // Already applied by whichever of the webhook or the return won.
            if ($payment->isSuccessful()) {
                return $payment;
            }

            if (! $verification->successful) {
                $payment->update(['status' => Payment::FAILED]);

                return $payment;
            }

            $due = (string) $payment->amount;

            /*
             * An underpayment is not settlement. Flutterwave will not normally
             * take less than asked, but a payment begun before the price
             * changed can arrive quoting the old figure.
             */
            if (bccomp($verification->amount, $due, 2) === -1) {
                Log::warning('Payment verified for less than was asked', [
                    'reference' => $payment->provider_reference,
                    'paid' => $verification->amount,
                    'due' => $due,
                ]);

                $payment->update(['status' => Payment::FAILED]);

                return $payment;
            }

            // The other side of the same coin. The money is real so it settles,
            // but the difference is owed back, and an admin can only act on
            // that if it was written down.
            if (bccomp($verification->amount, $due, 2) === 1) {
                Log::warning('Payment verified for more than was asked', [
                    'reference' => $payment->provider_reference,
                    'paid' => $verification->amount,
                    'due' => $due,
                ]);
            }

            $payment->update(['status' => Payment::SUCCESSFUL, 'paid_at' => now()]);

            if ($payment->purpose === Payment::PURPOSE_ORDER && $payment->order_id) {
                $this->applyToOrder($payment);
            }

            /*
             * A subscription payment has no order; it buys days.
             *
             * StartTerm is idempotent on the payment -- subscription_terms
             * has a unique index on payment_id -- so a webhook replayed by
             * Flutterwave and a browser returning twice cannot between them
             * mint two months. The lock above already prevents this branch
             * running twice; the index is the guard that does not depend on
             * the lock.
             */
            if ($payment->purpose === Payment::PURPOSE_SUBSCRIPTION) {
                $this->terms->fromPayment($payment);
            }

            return $payment->fresh();
        });
    }

    private function applyToOrder(Payment $payment): void
    {
        /** @var Order $order */
        $order = Order::query()->whereKey($payment->order_id)->lockForUpdate()->first();

        /*
         * Only an order still waiting on payment advances. One already in
         * progress, ready or cancelled must not be dragged backwards by a late
         * webhook -- delayed settlement on bank transfer and USSD means "late"
         * here can mean hours.
         */
        if ($order->status !== Order::PENDING_PAYMENT) {
            return;
        }

        if (! $order->isPaidUpFront()) {
            // A deposit part-paid: the money is banked but work has not been
            // bought yet. She stays in pending_payment.
            return;
        }

        /*
         * forceFill, not update. `status` is deliberately not mass-assignable
         * on Order so that no request body can ever contain it; that also
         * means our own transitions have to be explicit about bypassing it.
         */
        $order->forceFill(['status' => Order::IN_PROGRESS])->save();

        /*
         * What the platform now owes the tailor, recorded the moment it holds
         * the money rather than computed at release. No commission is
         * deducted: the subscription is the revenue.
         *
         * Only for escrow orders. A direct order never touches the platform,
         * so there is nothing to pay out.
         */
        if ($order->isEscrow()) {
            Payout::recordFor($order, $order->paidTotal());
        }

        // Told once. A second confirmation cannot reach this branch.
        $order->tailor->notify(new OrderPaid($order->fresh()->load('customer', 'garmentType')));
    }
}
