<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Payments\RefundOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class RefundController extends Controller
{
    public function __invoke(Request $request, Order $order, RefundOrder $refunds): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $payout = $refunds->handle(
                $order,
                number_format((float) $validated['amount'], 2, '.', ''),
                $validated['reason'] ?? null,
            );
        } catch (RuntimeException $exception) {
            // 422 rather than 500: every one of these is a rule being
            // enforced, not something broken.
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'refunded_total' => (string) $payout->refunded_amount,
                'tailor_now_owed' => (string) $payout->net_amount,
            ],
        ]);
    }
}
