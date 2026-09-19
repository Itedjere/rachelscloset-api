<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Rules\NigerianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Every order, for an admin.
 *
 * A deliberate widening: the ordinary OrderController is scoped to the two
 * people on an order, and an admin is on none of them. Issuing a refund
 * without being able to see what was paid, by whom and whether it has already
 * gone out to the tailor would be guessing with somebody else's money.
 *
 * This is NOT a general bypass. It exposes an order and its money, which is
 * what a refund decision needs. Measurement photographs stay closed to admins
 * without a specific permission and a real dispute -- see FileAccess.
 */
class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', Rule::in([
                Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY,
                Order::COLLECTED, Order::COMPLETED, Order::CANCELLED, Order::DISPUTED,
            ])],
            // A reference read down the phone, or a phone number.
            'q' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $orders = Order::query()
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($validated['q'] ?? null, function ($query, string $term) {
                $phone = NigerianPhone::normalise($term);

                $query->where(function ($q) use ($term, $phone) {
                    $q->where('reference', 'like', '%'.$term.'%');

                    // Only treat it as a phone if it actually is one, so a
                    // reference search does not also sweep the user table.
                    if ($phone !== null) {
                        $q->orWhereHas('customer', fn ($c) => $c->where('phone', $phone))
                            ->orWhereHas('tailor', fn ($t) => $t->where('phone', $phone));
                    }
                });
            })
            ->with([
                'customer:id,name,phone,avatar_url',
                'tailor:id,name,phone,avatar_url',
                'garmentType:id,name',
                'payout',
            ])
            ->latest()
            ->paginate($validated['per_page'] ?? 25);

        return OrderResource::collection($orders);
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        return response()->json([
            'data' => OrderResource::make(
                // Steps and their photographs too: an admin deciding a refund
                // is deciding what the work was worth, and "what was paid" is
                // only half of that question.
                $order->load(['customer', 'tailor', 'garmentType', 'payments', 'payout', 'steps.photos']),
            )->resolve($request),
        ]);
    }
}
