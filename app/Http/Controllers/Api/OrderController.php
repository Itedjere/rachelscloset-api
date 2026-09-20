<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Services\Orders\AssembleOrderSteps;
use App\Services\Payments\ReleasePayout;
use App\Services\Reviews\RecalculateTailorRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Orders.
 *
 * THE TAILOR CREATES THE ORDER, not the customer. That is not an arbitrary
 * choice: the two of them are standing in a shop together when measurements
 * are taken, and she is the one who knows what is being made and what it
 * costs. A customer-initiated flow would mean a quote-and-accept round trip
 * for a conversation that already happened out loud.
 */
class OrderController extends Controller
{
    public function __construct(private readonly RecalculateTailorRating $ratings) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY,
                Order::COLLECTED, Order::COMPLETED, Order::CANCELLED, Order::DISPUTED,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = Order::query()
            // Scoped to the person asking, always. There is no parameter for
            // whose orders to list, so there is nothing to tamper with.
            ->involving($request->user())
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->with(['customer:id,name,phone,avatar_url', 'tailor:id,name,phone,avatar_url', 'garmentType:id,name'])
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        // 404, not 403: whether an order exists is not something to confirm to
        // somebody with no part in it.
        abort_unless($order->involves($request->user()), 404);

        return response()->json([
            'data' => OrderResource::make(
                $order->load(['customer', 'tailor', 'garmentType', 'payments', 'payout', 'steps.photos']),
            )->resolve($request),
        ]);
    }

    public function store(Request $request, AssembleOrderSteps $assemble): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 403, 'Only a tailor can open an order.');

        $validated = $request->validate([
            'customer_id' => ['required', Rule::exists('users', 'id')->where('role', User::ROLE_CUSTOMER)],
            'garment_type_id' => [
                'required',
                // A retired garment type cannot take new orders, but the ones
                // already placed against it keep reading correctly.
                Rule::exists('garment_types', 'id')->whereNull('retired_at'),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'deposit_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'escrow' => ['nullable', 'boolean'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
        ]);

        $order = Order::create($validated + [
            'tailor_id' => $tailor->id,
            'deposit_amount' => $validated['deposit_amount'] ?? 0,
            'escrow' => $validated['escrow'] ?? false,
        ]);

        /*
         * The checklist is copied on now, not at payment, so the customer can
         * see what stages her garment will pass through while she is deciding
         * whether to pay. Having to hand over money to find out what happens
         * next is the thing this platform exists to fix.
         */
        $assemble->handle($order);

        return response()->json([
            'data' => OrderResource::make(
                $order->fresh()->load(['customer', 'tailor', 'garmentType', 'steps.photos']),
            )->resolve($request),
        ], 201);
    }

    /**
     * The garment is finished and waiting.
     *
     * The state the brief was missing. A collection deadline starts running
     * here, which is what makes "customer will not collect" something the
     * platform can act on rather than a problem the tailor absorbs.
     */
    public function markReady(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($order->status === Order::IN_PROGRESS, 422, 'This order is not in progress.');

        $days = (int) PlatformSetting::get(PlatformSetting::COLLECTION_DEADLINE_DAYS, 14);

        // forceFill: `status` is not mass-assignable, so a request body can
        // never carry a state change. See the Order model.
        $order->forceFill([
            'status' => Order::READY,
            'ready_at' => now(),
            'collection_deadline' => now()->addDays($days)->toDateString(),
        ])->save();

        return $this->respond($request, $order);
    }

    /** Handed over. Escrow release is Section 12. */
    /**
     * The tailor hands it over -- across a counter, or to a courier.
     *
     * SHE SAYS WHICH, because the two are not the same event and the escrow
     * clock depends on the difference. A customer standing in the shop has
     * the garment the moment this is tapped; a customer four hundred
     * kilometres away has a tracking number and a week to wait.
     *
     * Collected in person therefore starts the clock at once, which keeps
     * the ordinary local order paying out in three days as it always has.
     * Posted waits for the customer to confirm it arrived, with a long
     * backstop so silence cannot strand the money. See escrowReleaseDue().
     */
    public function markCollected(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($order->status === Order::READY, 422, 'This order is not ready yet.');

        $validated = $request->validate([
            'posted' => ['nullable', 'boolean'],
        ]);

        $posted = (bool) ($validated['posted'] ?? false);

        $order->forceFill([
            'status' => Order::COLLECTED,
            'collected_at' => now(),
            // In her hands already, so there is nothing to wait for.
            'received_at' => $posted ? null : now(),
        ])->save();

        return $this->respond($request, $order);
    }

    /**
     * "It arrived."
     *
     * The customer's own action, and the thing that starts the escrow clock.
     * The tailor marking an order collected is her handing it over or
     * posting it; for a remote customer those are a week apart, and running
     * the clock from dispatch meant the money could be gone before the box
     * was opened. See Order::escrowReleaseDue().
     *
     * Confirming receipt is NOT saying she is happy with it. She can still
     * raise a problem afterwards, and the two are separate buttons saying
     * separate things.
     */
    public function markReceived(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        abort_unless($order->status === Order::COLLECTED, 422, 'This order has not been sent yet.');

        // Idempotent: two taps on a slow connection must not move the clock.
        if ($order->received_at === null) {
            $order->forceFill(['received_at' => now()])->save();
        }

        return $this->respond($request, $order);
    }

    /**
     * The customer says she is happy.
     *
     * Completes the order and releases escrow at once -- there is nothing left
     * to wait for once the person who paid says the garment is right. This is
     * the fast path; the slow one is escrowReleaseDue() for a customer who
     * simply never comes back to say anything.
     */
    public function confirm(Request $request, Order $order, ReleasePayout $release): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        abort_unless($order->status === Order::COLLECTED, 422, 'This order has not been collected yet.');

        /*
         * Not while something is being looked into. Confirming happiness
         * releases the money at once, which would walk straight past the
         * freeze the dispute exists to apply -- and the person tapping it
         * may well be the one who complained.
         */
        abort_if(
            $order->hasOpenDispute(),
            422,
            'We are looking into this order. We will call you.',
        );

        $order->forceFill(['status' => Order::COMPLETED, 'completed_at' => now()])->save();

        if ($order->isEscrow() && $order->payout) {
            $release->handle($order->payout);
        }

        // orders_completed on her public profile is derived from this status,
        // so it moves here rather than drifting until somebody reviews her.
        $this->ratings->handle($order->tailor);

        return $this->respond($request, $order);
    }

    /**
     * The tailor releasing escrow after the waiting period.
     *
     * Her own action, not only a scheduled sweep, so a stopped cron costs a
     * tap rather than stranding her money.
     */
    public function release(Request $request, Order $order, ReleasePayout $release): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($order->isEscrow(), 422, 'This order was paid to you directly.');
        abort_unless($order->payout, 422, 'There is nothing held for this order.');
        abort_unless(
            $order->escrowReleaseDue(),
            422,
            'The customer still has time to raise a problem with this order.',
        );

        if ($order->status === Order::COLLECTED) {
            $order->forceFill(['status' => Order::COMPLETED, 'completed_at' => now()])->save();
            $this->ratings->handle($order->tailor);
        }

        $release->handle($order->payout);

        return $this->respond($request, $order);
    }

    /**
     * Called off.
     *
     * Only before work is bought. Once an order is in progress there is cloth
     * cut and money held, and unwinding that is a refund, not a cancellation.
     */
    public function cancel(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->involves($request->user()), 404);
        abort_unless($order->status === Order::PENDING_PAYMENT, 422, 'Work has already started on this order.');

        $order->forceFill(['status' => Order::CANCELLED, 'cancelled_at' => now()])->save();

        return $this->respond($request, $order);
    }

    /** One shape for every response that hands back an order. */
    private function respond(Request $request, Order $order): JsonResponse
    {
        return response()->json([
            'data' => OrderResource::make(
                $order->fresh()->load(['customer', 'tailor', 'garmentType', 'payout', 'steps.photos']),
            )->resolve($request),
        ]);
    }
}
