<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Notifications\OrderDisputed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Saying something is wrong.
 *
 * The customer's side of it, and deliberately thin: one button and a
 * sentence. She is not asked to categorise the problem, grade its severity
 * or upload evidence, because the next thing that happens is a person
 * telephoning her and the form would only be asking her to do badly in
 * writing what she is about to do well out loud.
 *
 * What raising one actually DOES is freeze the money -- see
 * Order::escrowReleaseDue(). Everything else here is a record so that the
 * calls can be made.
 */
class DisputeController extends Controller
{
    /** What is on this order, for the two people on it. */
    public function forOrder(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->involves($request->user()), 404);

        $dispute = $order->disputes()->with(['raisedBy', 'resolvedBy'])->latest('id')->first();

        return response()->json([
            'data' => $dispute ? $this->shape($dispute) : null,
            // Only the customer may raise one, and only once the garment is
            // actually in her hands to judge.
            'can_raise' => $this->canRaise($request->user(), $order),
        ]);
    }

    public function store(Request $request, Order $order): JsonResponse
    {
        $customer = $request->user();

        abort_unless($order->customer_id === $customer->id, 404);

        abort_unless(
            $this->canRaise($customer, $order),
            422,
            $order->hasOpenDispute()
                ? 'You have already told us about this order. We will call you.'
                : 'You can tell us about a problem once you have the garment.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $dispute = DB::transaction(function () use ($order, $customer, $validated) {
            /*
             * Locked and re-checked. Two taps on a slow connection is the
             * ordinary way a second dispute would appear, and the schema
             * cannot forbid it -- see the migration.
             */
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->first();

            if ($order->hasOpenDispute()) {
                return $order->openDispute();
            }

            return Dispute::create([
                'order_id' => $order->id,
                'raised_by' => $customer->id,
                'reason' => $validated['reason'],
            ]);
        });

        /*
         * The tailor is told, plainly and without accusation. She is about
         * to get a phone call, and finding out from the platform first is
         * kinder than being ambushed by it.
         */
        $order->tailor?->notify(new OrderDisputed($order, $dispute));

        return response()->json(['data' => $this->shape($dispute->fresh()->load('raisedBy'))], 201);
    }

    /**
     * Only the customer, only once she has the garment, and only while the
     * money is still here to freeze.
     */
    private function canRaise(User $user, Order $order): bool
    {
        if ($order->customer_id !== $user->id) {
            return false;
        }

        if ($order->hasOpenDispute()) {
            return false;
        }

        /*
         * Not before she has it. A garment still being made is a
         * conversation with the tailor, and the tracker is what that
         * conversation is for.
         */
        if (! in_array($order->status, [Order::COLLECTED, Order::COMPLETED], true)) {
            return false;
        }

        /*
         * Once the tailor has been paid there is nothing to freeze, and
         * taking money back from her afterwards means the platform pays
         * twice -- which RefundOrder already refuses. She can still ring us;
         * that is a conversation, not a button.
         */
        return ! ($order->payout?->isReleased() ?? false);
    }

    /** @return array<string, mixed> */
    private function shape(Dispute $dispute): array
    {
        return [
            'id' => $dispute->id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'raised_by' => $dispute->raisedBy?->only(['id', 'name']),
            'opened_by_staff' => $dispute->wasOpenedByStaff(),
            'outcome' => $dispute->outcome,
            'refunded_amount' => $dispute->refunded_amount ? (string) $dispute->refunded_amount : null,
            'resolution_note' => $dispute->resolution_note,
            'resolved_at' => $dispute->resolved_at,
            'created_at' => $dispute->created_at,
        ];
    }
}
