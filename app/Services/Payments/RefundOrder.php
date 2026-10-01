<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Payout;
use App\Notifications\OrderRefunded;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Money back to the customer.
 *
 * Admin-only, because a refund is a judgement about work that has usually
 * already been done. Partial is the common case, not the exception: a garment
 * that is late or not quite right is settled somewhere between nothing and
 * everything.
 *
 * Refunding reduces what the tailor is owed. That ordering matters -- taking
 * the money back from the customer and then paying the tailor in full would
 * leave the platform short, which is exactly the bug worth never having.
 */
class RefundOrder
{
    public function __construct(private readonly PaymentGatewayManager $gateways) {}

    public function handle(Order $order, string $amount, ?string $reason = null): Payout
    {
        if (bccomp($amount, '0', 2) !== 1) {
            throw new RuntimeException('A refund has to be more than nothing.');
        }

        return DB::transaction(function () use ($order, $amount, $reason) {
            /** @var Order $order */
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            /*
             * Only money the platform holds can be given back by it. A direct
             * order was paid to the tailor by hand; refunding it here would
             * mean Rachel's Closet paying out of its own pocket for money it
             * never received.
             */
            if (! $order->isEscrow()) {
                throw new RuntimeException('This order was paid straight to the tailor. Rachel\'s Closet holds nothing to refund.');
            }

            $paid = $order->paidTotal();

            if (bccomp($amount, $paid, 2) === 1) {
                throw new RuntimeException('That is more than has been paid on this order.');
            }

            $payout = $order->payout()->lockForUpdate()->first();

            /*
             * Refusing after release is deliberate. Once the money has left
             * for the tailor, taking it back from the customer is the
             * platform paying twice -- that is a conversation with the tailor,
             * not a button.
             */
            if ($payout && $payout->isReleased()) {
                throw new RuntimeException('This order has already been paid out to the tailor.');
            }

            $payment = $order->payments()
                ->where('status', Payment::SUCCESSFUL)
                ->orderByDesc('amount')
                ->first();

            if (! $payment) {
                throw new RuntimeException('Nothing has been paid on this order.');
            }

            // The provider call is last inside the transaction but first in
            // consequence: if it throws, nothing below is written.
            $this->gateways->for($payment->provider)->refund($payment, $amount);

            if ($payout) {
                $refunded = bcadd((string) $payout->refunded_amount, $amount, 2);

                $payout->forceFill([
                    'refunded_amount' => $refunded,
                    'net_amount' => bcsub((string) $payout->gross_amount, $refunded, 2),
                ])->save();
            }

            $order->customer->notify(new OrderRefunded($order, $amount, $reason));

            return $payout ?? Payout::recordFor($order, '0');
        });
    }
}
