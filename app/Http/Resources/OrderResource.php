<?php

namespace App\Http\Resources;

use App\Models\Order;
use App\Models\Payment;
use App\Support\StoredFile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'description' => $this->description,

            'amount' => (string) $this->amount,
            'deposit_amount' => (string) $this->deposit_amount,
            'paid_total' => $this->paidTotal(),
            'amount_due_up_front' => $this->amountDueUpFront(),
            'is_paid_up_front' => $this->isPaidUpFront(),
            'escrow' => $this->escrow,

            /*
             * What the tailor has recorded being handed, on a direct order.
             * Both of them see the list: it is the customer's receipt and the
             * tailor's way to take back a mistyped amount. Empty on escrow
             * orders, whose payments went through the platform.
             */
            'direct_payments' => $this->escrow ? [] : $this->payments()
                ->where('provider', Payment::PROVIDER_DIRECT)
                ->where('status', Payment::SUCCESSFUL)
                ->orderBy('paid_at')
                ->get(['id', 'amount', 'paid_at'])
                ->map(fn (Payment $payment) => [
                    'id' => $payment->id,
                    'amount' => (string) $payment->amount,
                    'paid_at' => $payment->paid_at,
                ])
                ->all(),

            'due_date' => $this->due_date?->toDateString(),
            'ready_at' => $this->ready_at,
            'collection_deadline' => $this->collection_deadline?->toDateString(),
            'collected_at' => $this->collected_at,
            // Null on a posted garment until she says it arrived, which is
            // what starts the escrow clock -- see Order::escrowReleaseDue().
            'received_at' => $this->received_at,
            'completed_at' => $this->completed_at,

            // Section 9 writes these. Zero until then.
            'steps_total' => $this->steps_total,
            'steps_completed' => $this->steps_completed,
            // How much of the work was actually shown. Section 13's review
            // gate reads this; the order page shows it to both sides.
            'steps_with_photo' => $this->steps_with_photo,

            // Whether the tailor may release escrow herself yet. Computed
            // from collected_at, so it is true the moment it is true.
            'can_release' => $this->escrowReleaseDue(),

            'payout' => $this->whenLoaded('payout', fn () => $this->payout ? [
                'net_amount' => (string) $this->payout->net_amount,
                'refunded_amount' => (string) $this->payout->refunded_amount,
                'status' => $this->payout->status,
                'failure_reason' => $this->payout->failure_reason,
            ] : null),

            /*
             * Only loaded for the order detail and the admin screens. A
             * refund decision needs to see what actually arrived and when --
             * a part payment followed by silence looks very different from
             * one clean settlement.
             */
            'steps' => $this->whenLoaded(
                'steps',
                fn () => OrderStepResource::collection($this->steps),
            ),

            'payments' => $this->whenLoaded('payments', fn () => $this->payments
                ->map(fn ($payment) => [
                    'id' => $payment->id,
                    'reference' => $payment->provider_reference,
                    'amount' => (string) $payment->amount,
                    'status' => $payment->status,
                    'paid_at' => $payment->paid_at,
                ])->values()),

            'garment_type' => $this->whenLoaded('garmentType', fn () => [
                'id' => $this->garmentType->id,
                'name' => $this->garmentType->name,
            ]),
            'customer' => $this->whenLoaded('customer', fn () => $this->person($this->customer)),
            'tailor' => $this->whenLoaded('tailor', fn () => $this->person($this->tailor)),

            'created_at' => $this->created_at,
        ];
    }

    private function person($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'avatar_url' => StoredFile::url($user->avatar_url),
            // A customer added from the shop floor cannot sign in -- so she
            // cannot pay or follow the tracker -- until she claims. The order
            // page puts the invite in front of the tailor while this is false.
            'claimed' => $user->isClaimed(),
        ];
    }
}
