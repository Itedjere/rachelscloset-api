<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderStepResource;
use App\Models\Order;
use App\Models\OrderStep;
use App\Notifications\StepCompleted;
use App\Services\Orders\RecalculateOrderProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ticking off the stages of an order.
 *
 * The tap-to-complete checklist, which CLAUDE.md calls the product. One tap by
 * the tailor, one notification to the customer, no typing at either end.
 */
class OrderStepController extends Controller
{
    public function __construct(private readonly RecalculateOrderProgress $progress) {}

    /** Both sides read the same list. */
    public function index(Request $request, Order $order): JsonResponse
    {
        abort_unless($order->involves($request->user()), 404);

        return response()->json(['data' => $this->shapeAll($order)]);
    }

    /**
     * Tick a stage off, or put it back.
     *
     * Un-ticking is allowed because a mis-tap on a 5-inch screen is ordinary
     * and the alternative is a tailor with a wrong timeline she cannot fix.
     * The customer is only told on the way to done, so correcting a slip does
     * not buzz her phone a second time.
     */
    public function update(Request $request, Order $order, OrderStep $step): JsonResponse
    {
        abort_unless($order->tailor_id === $request->user()->id, 404);
        abort_unless($step->order_id === $order->id, 404);

        abort_unless(
            in_array($order->status, [Order::IN_PROGRESS, Order::READY], true),
            422,
            'This order is not being worked on.',
        );

        $validated = $request->validate(['complete' => ['required', 'boolean']]);

        $wasComplete = $step->isComplete();

        $step->forceFill([
            'completed_at' => $validated['complete'] ? now() : null,
            'completed_by' => $validated['complete'] ? $request->user()->id : null,
        ])->save();

        $wasReady = $order->status === Order::READY;

        $order = $this->progress->handle($order);

        /*
         * Only on the transition into done, and only if it really changed.
         *
         * And not at all when that tick is what finished the garment: the
         * order-is-ready notice covers the same moment and says more. Sending
         * both puts two lines on her phone one second apart -- and because
         * the last stage in most arrangements is called something like "Ready
         * to collect", they read as the same sentence twice.
         */
        $justBecameReady = ! $wasReady && $order->status === Order::READY;

        if ($validated['complete'] && ! $wasComplete && ! $justBecameReady) {
            $order->customer->notify(
                new StepCompleted($order->load('garmentType'), $step->fresh()),
            );
        }

        return response()->json([
            'data' => [
                'steps' => $this->shapeAll($order),
                'order_status' => $order->status,
                'steps_total' => $order->steps_total,
                'steps_completed' => $order->steps_completed,
            ],
        ]);
    }

    private function shapeAll(Order $order): array
    {
        return OrderStepResource::collection(
            $order->steps()->with('photos')->get(),
        )->resolve();
    }
}
