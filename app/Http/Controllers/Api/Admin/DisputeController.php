<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Notifications\DisputeResolved;
use App\Notifications\OrderDisputed;
use App\Services\Payments\RefundOrder;
use App\Services\Payments\ReleasePayout;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Deciding what to do about it.
 *
 * THE PLATFORM DOES NOT ADJUDICATE. It froze the money when the dispute was
 * raised; this screen exists to put both phone numbers in front of a person,
 * and then to carry out whatever she agreed on the calls. That is how the
 * business already resolves these, and a structured evidence exchange would
 * be inventing a process nobody asked for and few of these users could work.
 *
 * Three outcomes, which are the three things that can actually happen to the
 * money: it goes back to the customer, it goes on to the tailor, or the
 * customer rings to say the parcel turned up and nothing needs to happen. A
 * partial refund is the first with an amount, and the remainder goes to the
 * tailor in the same act -- the split every real negotiation ends in.
 */
class DisputeController extends Controller
{
    public function __construct(
        private readonly RefundOrder $refunds,
        private readonly ReleasePayout $payouts,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $disputes = Dispute::query()
            ->with(['order.customer', 'order.tailor', 'order.garmentType', 'order.payout', 'raisedBy'])
            ->where('status', Dispute::OPEN)
            ->oldest('id')
            ->get();

        return response()->json([
            'data' => $disputes->map(fn (Dispute $dispute) => $this->shape($dispute)),
        ]);
    }

    /**
     * Open one after a phone call.
     *
     * She may be ringing precisely because she cannot work the app, which is
     * the whole reason this exists. The dispute is recorded against her, not
     * against the admin, so the record says who complained -- but
     * `wasOpenedByStaff()` lets the screen say it was taken down over the
     * phone rather than implying she tapped a button.
     */
    public function store(Request $request, Order $order): JsonResponse
    {
        abort_if($order->hasOpenDispute(), 422, 'This order already has an open dispute.');

        // Same rule as the customer's button: no held money, nothing to settle.
        abort_unless(
            $order->isEscrow(),
            422,
            'This order was paid straight to the tailor. Rachel\'s Closet holds no money on it to settle.',
        );

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:4', 'max:2000'],
        ]);

        $dispute = Dispute::create([
            'order_id' => $order->id,
            'raised_by' => $request->user()->id,
            'reason' => $validated['reason'],
        ]);

        $order->tailor?->notify(new OrderDisputed($order, $dispute));

        return response()->json(['data' => $this->shape($dispute->load('order', 'raisedBy'))], 201);
    }

    /**
     * Carry out what was agreed on the calls.
     *
     * The money moves here and only here. Both services already exist and
     * already refuse the things they should -- RefundOrder will not take
     * money back after a payout has been released, because that is the
     * platform paying twice.
     */
    public function resolve(Request $request, Dispute $dispute): JsonResponse
    {
        abort_unless($dispute->isOpen(), 422, 'That dispute has already been settled.');

        $order = $dispute->order;
        $paid = $order->paidTotal();

        $validated = $request->validate([
            'outcome' => ['required', Rule::in([Dispute::REFUNDED, Dispute::RELEASED, Dispute::WITHDRAWN])],
            // Only on a refund. Absent means all of it.
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:'.$paid],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $refunded = null;

        try {
            DB::transaction(function () use ($validated, $dispute, $order, $paid, $request, &$refunded) {
                if ($validated['outcome'] === Dispute::REFUNDED) {
                    $refunded = isset($validated['amount'])
                        ? number_format((float) $validated['amount'], 2, '.', '')
                        : $paid;

                    $this->refunds->handle($order, $refunded, $validated['note'] ?? 'Agreed after a call');
                }

                /*
                 * What is left goes to the tailor -- so a full refund pays
                 * her nothing, a partial pays her the balance, and
                 * "released" pays her everything. One branch covers the
                 * split, which is what most of these actually end in.
                 */
                if ($validated['outcome'] !== Dispute::WITHDRAWN) {
                    $payout = $order->fresh()->payout;

                    if ($payout && ! $payout->isReleased() && bccomp((string) $payout->net_amount, '0', 2) === 1) {
                        $this->payouts->handle($payout);
                    }
                }

                $dispute->forceFill([
                    'status' => Dispute::RESOLVED,
                    'outcome' => $validated['outcome'],
                    'refunded_amount' => $refunded,
                    'resolution_note' => $validated['note'] ?? null,
                    'resolved_by' => $request->user()->id,
                    'resolved_at' => now(),
                ])->save();

                /*
                 * The order finishes here. It was held open only because the
                 * money was, and leaving it in `collected` for ever would
                 * mean the escrow sweep kept looking at it.
                 */
                if (in_array($order->status, [Order::COLLECTED, Order::DISPUTED], true)) {
                    $order->forceFill([
                        'status' => Order::COMPLETED,
                        'completed_at' => now(),
                    ])->save();
                }
            });
        } catch (RuntimeException $exception) {
            // The refund provider refused, or the money had already gone.
            // Nothing above was written.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        // Both sides are told what was decided, and both are told the same
        // thing -- there is no version of this each party hears separately.
        foreach ([$order->customer, $order->tailor] as $party) {
            $party?->notify(new DisputeResolved($order, $dispute->fresh()));
        }

        return response()->json(['data' => $this->shape($dispute->fresh()->load('order', 'raisedBy', 'resolvedBy'))]);
    }

    /** @return array<string, mixed> */
    private function shape(Dispute $dispute): array
    {
        $order = $dispute->order;

        return [
            'id' => $dispute->id,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'opened_by_staff' => $dispute->wasOpenedByStaff(),
            'created_at' => $dispute->created_at,

            'outcome' => $dispute->outcome,
            'refunded_amount' => $dispute->refunded_amount ? (string) $dispute->refunded_amount : null,
            'resolution_note' => $dispute->resolution_note,
            'resolved_at' => $dispute->resolved_at,

            'order' => $order ? [
                'id' => $order->id,
                'reference' => $order->reference,
                'garment' => $order->garmentType?->name,
                'amount' => (string) $order->amount,
                'paid' => $order->paidTotal(),
                'escrow' => $order->escrow,
                'collected_at' => $order->collected_at,
                'received_at' => $order->received_at,
                // Held, so an admin knows what there is to argue over before
                // she picks up the phone.
                'held' => $order->payout && ! $order->payout->isReleased()
                    ? (string) $order->payout->net_amount
                    : '0.00',
            ] : null,

            /*
             * Both numbers, because resolving this is two phone calls. This
             * is the whole point of the screen.
             */
            'customer' => $this->party($order?->customer),
            'tailor' => $this->party($order?->tailor),
        ];
    }

    /** @return array<string, mixed>|null */
    private function party(?User $user): ?array
    {
        return $user ? [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'whatsapp' => WhatsApp::to($user->phone),
        ] : null;
    }
}
