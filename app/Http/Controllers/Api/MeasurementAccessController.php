<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TailorCustomerLink;
use App\Services\Measurements\MeasurementAccess;
use App\Support\StoredFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Who can see my measurements.
 *
 * A consent screen is only worth having if it is legible and truthful, so
 * this returns one row per tailor with the plain-language reason she has
 * access -- and, where revoking will not actually remove it yet, says so.
 *
 * The whole list belongs to the person asking. There is no parameter for
 * whose consents to read.
 */
class MeasurementAccessController extends Controller
{
    public function __construct(private readonly MeasurementAccess $access) {}

    public function index(Request $request): JsonResponse
    {
        $customer = $request->user();

        $links = TailorCustomerLink::query()
            ->with('tailor.tailorProfile')
            ->where('customer_id', $customer->id)
            ->latest('id')
            ->get();

        /*
         * A live order grants access on its own, with no link at all -- see
         * MeasurementAccess. A consent screen that listed only links would
         * therefore be a lie by omission, so those tailors appear here too.
         */
        $liveTailorIds = Order::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY, Order::DISPUTED])
            ->pluck('tailor_id')
            ->unique();

        $rows = $links->map(function (TailorCustomerLink $link) use ($liveTailorIds) {
            $live = $liveTailorIds->contains($link->tailor_id);

            return [
                'id' => $link->id,
                'tailor' => [
                    'id' => $link->tailor?->id,
                    'name' => $link->tailor?->name,
                    'business_name' => $link->tailor?->tailorProfile?->business_name,
                    // The routed URL, never the stored path: uploads live on
                    // the private disk, so the bare path is a broken image.
                    'avatar_url' => StoredFile::url($link->tailor?->avatar_url),
                ],
                'granted' => $link->isGranted(),
                'granted_at' => $link->granted_at,
                'revoked_at' => $link->revoked_at,
                'has_live_order' => $live,
                /*
                 * The honest label. Revoking while a garment is being made
                 * does not take the measurements back -- the cloth is cut and
                 * she has them on paper -- so the button must not imply it
                 * does.
                 */
                'can_see_now' => $link->isGranted() || $live,
                'reason' => $link->isGranted()
                    ? ($live ? 'You allowed her, and she is making something for you.' : 'You allowed her.')
                    : ($live ? 'She is making something for you right now.' : 'She cannot see them.'),
            ];
        });

        // Tailors with a live order but no link at all.
        $linked = $links->pluck('tailor_id');

        $orphans = Order::query()
            ->with('tailor.tailorProfile')
            ->where('customer_id', $customer->id)
            ->whereIn('status', [Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY, Order::DISPUTED])
            ->whereNotIn('tailor_id', $linked)
            ->get()
            ->unique('tailor_id')
            ->map(fn (Order $order) => [
                'id' => null,
                'tailor' => [
                    'id' => $order->tailor?->id,
                    'name' => $order->tailor?->name,
                    'business_name' => $order->tailor?->tailorProfile?->business_name,
                    'avatar_url' => StoredFile::url($order->tailor?->avatar_url),
                ],
                'granted' => false,
                'granted_at' => null,
                'revoked_at' => null,
                'has_live_order' => true,
                'can_see_now' => true,
                'reason' => 'She is making something for you right now.',
            ]);

        return response()->json(['data' => $rows->concat($orphans)->values()]);
    }

    /** Take it back. */
    public function revoke(Request $request, TailorCustomerLink $link): JsonResponse
    {
        abort_unless($link->customer_id === $request->user()->id, 404);

        $link->revoke();

        return response()->json(['data' => ['granted' => false]]);
    }

    /** And give it again, which is one tap because the row was kept. */
    public function grant(Request $request, TailorCustomerLink $link): JsonResponse
    {
        abort_unless($link->customer_id === $request->user()->id, 404);

        $link->grant();

        return response()->json(['data' => ['granted' => true]]);
    }
}
