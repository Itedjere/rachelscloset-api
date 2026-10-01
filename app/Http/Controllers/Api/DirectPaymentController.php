<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Notifications\DirectPaymentRecorded;
use App\Support\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "She has paid me": the tailor recording money handed to her on a direct order.
 *
 * A direct order never touches the platform -- the customer pays the tailor in
 * cash, by transfer or at a POS -- so the only way the app can know is for the
 * tailor to say so. Trusting her is safe in the one direction that matters:
 * the only person a false entry can hurt is the tailor herself, by claiming
 * money she was not given. And the customer is sent a receipt for every entry,
 * so a wrong amount is caught when it is written rather than at the counter.
 *
 * Each entry is an ordinary Payment with provider `direct`, so "paid so far",
 * "still owing" and the collection reminders read one source of truth.
 */
class DirectPaymentController extends Controller
{
    public function store(Request $request, Order $order): JsonResponse
    {
        $this->authoriseTailor($request, $order);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
        ], [
            'amount.gt' => 'Write how much she gave you.',
        ]);

        $payment = DB::transaction(function () use ($order, $validated) {
            /*
             * Locked and re-counted. Two taps on a slow connection is the
             * ordinary way an amount would be recorded twice, and the cap
             * below is only meaningful against the total as it is NOW.
             */
            /** @var Order $order */
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            $amount = number_format((float) $validated['amount'], 2, '.', '');
            $outstanding = bcsub((string) $order->amount, $order->paidTotal(), 2);

            // Never more than is owed: "paid so far" above the price is a
            // record nobody can make sense of, and it is always a typo.
            if (bccomp($amount, $outstanding, 2) === 1) {
                throw ValidationException::withMessages([
                    'amount' => bccomp($outstanding, '0', 2) === 1
                        ? 'That is more than she still owes ('.Naira::format($outstanding).').'
                        : 'This order is already fully paid.',
                ]);
            }

            $payment = Payment::create([
                'purpose' => Payment::PURPOSE_ORDER,
                'order_id' => $order->id,
                'payer_id' => $order->customer_id,
                'provider' => Payment::PROVIDER_DIRECT,
                // Unique like every other reference, and readable as what it
                // is when it turns up on the admin's order screen.
                'provider_reference' => $order->reference.'-DIRECT-'.Str::upper(Str::random(8)),
                'amount' => $amount,
                'status' => Payment::SUCCESSFUL,
                'paid_at' => now(),
            ]);

            /*
             * The same step a confirmed Flutterwave payment takes: once what
             * is due up front is covered, the work is bought and the tracker
             * opens. Only forwards, and only from pending_payment.
             */
            if ($order->status === Order::PENDING_PAYMENT && $order->isPaidUpFront()) {
                $order->forceFill(['status' => Order::IN_PROGRESS])->save();
            }

            return $payment;
        });

        $order->refresh()->load('tailor.tailorProfile', 'garmentType');
        $order->customer?->notify(new DirectPaymentRecorded($order, $payment));

        return response()->json(['data' => $this->shape($payment)], 201);
    }

    /**
     * Taking back an entry made by mistake.
     *
     * Allowed until the order is finished, because a thumb on a five-inch
     * screen will sometimes record ₦20,000 that was ₦2,000. The order's status
     * is NOT moved back: the cloth may already be cut, and un-starting work is
     * not something a corrected number should do. Nobody is notified -- the
     * receipt she got stands as a record of what was said at the time.
     */
    public function destroy(Request $request, Order $order, Payment $payment): JsonResponse
    {
        $this->authoriseTailor($request, $order);

        abort_unless($payment->order_id === $order->id && $payment->isDirect(), 404);
        abort_if(
            $order->status === Order::COMPLETED,
            422,
            'This order is finished, so its payments can no longer be changed.',
        );

        $payment->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function authoriseTailor(Request $request, Order $order): void
    {
        // 404 to anybody not on it, the same as every other order route.
        abort_unless($order->involves($request->user()), 404);
        abort_unless($order->tailor_id === $request->user()->id, 403, 'Only the tailor can record this.');

        abort_if($order->isEscrow(), 422, 'This order is paid through Rachels Closet, not to you directly.');
        abort_if($order->status === Order::CANCELLED, 422, 'This order was cancelled.');
    }

    /** @return array<string, mixed> */
    private function shape(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'paid_at' => $payment->paid_at,
        ];
    }
}
