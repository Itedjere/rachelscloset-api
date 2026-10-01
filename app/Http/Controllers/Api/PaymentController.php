<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\ConfirmPayment;
use App\Services\Payments\PaymentGatewayManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly ConfirmPayment $confirm,
    ) {}

    /**
     * Start paying for an order.
     *
     * Returns a URL to send the payer to. The row is written first so the
     * reference exists before the provider ever hears about it -- a payment
     * that arrives for a reference we did not issue is a payment we refuse to
     * apply, and that is only possible if we issue it first.
     */
    public function initialise(Request $request, Order $order): JsonResponse
    {
        $user = $request->user();

        // Only the customer pays, and only 404 otherwise: whether an order
        // exists is not something to confirm to somebody with no part in it.
        abort_unless($order->involves($user), 404);
        abort_unless($order->customer_id === $user->id, 403, 'Only the customer can pay for this order.');

        abort_unless(
            $order->status === Order::PENDING_PAYMENT,
            422,
            'This order is not waiting for payment.',
        );

        /*
         * A direct order is paid to the tailor by hand. Charging it here took
         * the money into the PLATFORM's Flutterwave account with no payout
         * recorded -- the tailor was never paid, while the screen told both of
         * them "paid straight to your tailor". She records it instead; see
         * DirectPaymentController.
         */
        abort_if(
            ! $order->isEscrow(),
            422,
            'Pay your tailor directly for this order. She will mark it as paid.',
        );

        abort_unless($this->gateways->configured(), 503, 'Payments are not set up on this server.');

        $due = bcsub($order->amountDueUpFront(), $order->paidTotal(), 2);

        abort_unless(bccomp($due, '0', 2) === 1, 422, 'Nothing is outstanding on this order.');

        $payment = Payment::create([
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order->id,
            'payer_id' => $user->id,
            'provider' => $this->gateways->active()->name(),
            /*
             * Ours, not theirs, and it carries the order reference so a row in
             * the Flutterwave dashboard can be matched to an order without
             * cross-referencing anything. The order reference already begins
             * "RC-", so nothing is prefixed here -- doing both produced
             * "RC-RC-...".
             *
             * Not just the order reference: an order can be paid more than
             * once (a deposit, then the balance) and the column is unique.
             */
            'provider_reference' => $order->reference.'-'.Str::upper(Str::random(8)),
            'amount' => $due,
            'status' => Payment::PENDING,
        ]);

        try {
            $link = $this->gateways->active()->initialise(
                $payment->load('payer'),
                rtrim((string) config('app.frontend_url'), '/').'/orders/'.$order->id.'/paid',
                'Payment for '.($order->garmentType?->name ?? 'your order').' — '.$order->reference,
            );
        } catch (RuntimeException $exception) {
            // The row stays, marked failed, so the attempt is visible when
            // somebody asks why nothing happened.
            $payment->update(['status' => Payment::FAILED]);

            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json([
            'data' => [
                'reference' => $payment->provider_reference,
                'amount' => (string) $payment->amount,
                'link' => $link,
            ],
        ], 201);
    }

    /**
     * The payer has come back from the provider.
     *
     * The browser is not believed about anything except which reference to
     * look at -- ConfirmPayment asks the provider directly. This races the
     * webhook by design, and whichever arrives second is a no-op.
     */
    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:120'],
        ]);

        $payment = $this->confirm->handle($validated['reference']);

        if (! $payment) {
            return response()->json(['message' => 'That payment reference is not one of ours.'], 404);
        }

        // A payer can only ask about their own payment.
        abort_unless($payment->payer_id === $request->user()->id, 404);

        return response()->json([
            'data' => [
                'status' => $payment->status,
                'paid' => $payment->isSuccessful(),
                'order_status' => $payment->order?->fresh()->status,
            ],
        ]);
    }
}
