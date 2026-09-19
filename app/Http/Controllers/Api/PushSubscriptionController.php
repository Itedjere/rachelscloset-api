<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\SendPushMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The browsers that have agreed to receive alerts.
 *
 * A subscription is issued by the browser's own push service, not by us -- all
 * this stores is where to send and the keys to encrypt with.
 */
class PushSubscriptionController extends Controller
{
    public function __construct(private readonly SendPushMessage $push) {}

    /**
     * What the browser needs before it can ask permission.
     *
     * The public key is not a secret -- it is handed to every browser that
     * subscribes. `enabled` being false is how the app knows to hide the whole
     * prompt rather than offer a button that cannot work.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'data' => [
                'enabled' => $this->push->configured(),
                'public_key' => config('services.push.public_key'),
            ],
        ]);
    }

    /** Devices this person has hooked up, so they can see and unhook them. */
    public function index(Request $request): JsonResponse
    {
        $devices = $request->user()->pushSubscriptions()
            ->latest('id')
            ->get()
            ->map(fn (PushSubscription $subscription) => [
                'id' => $subscription->id,
                'label' => $subscription->device_label ?? 'Unknown device',
                'last_used_at' => $subscription->last_used_at,
                'created_at' => $subscription->created_at,
            ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Records a browser's subscription.
     *
     * Keyed on the endpoint rather than the user: the endpoint identifies the
     * browser, so a shop phone handed to a second account has to stop receiving
     * the first account's alerts rather than receiving both. That is not a
     * hypothetical here -- one phone between several people is ordinary.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $validated['endpoint']],
            [
                'user_id' => $request->user()->id,
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'device_label' => PushSubscription::labelFor($request->userAgent()),
            ],
        );

        return response()->json([
            'data' => [
                'id' => $subscription->id,
                'label' => $subscription->device_label,
            ],
        ], 201);
    }

    /** Turning alerts off, either on this device or on a named one. */
    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'endpoint' => ['nullable', 'string', 'max:500'],
            'id' => ['nullable', 'integer'],
        ]);

        $query = $request->user()->pushSubscriptions();

        if ($validated['endpoint'] ?? null) {
            $query->where('endpoint', $validated['endpoint']);
        } elseif ($validated['id'] ?? null) {
            $query->whereKey($validated['id']);
        }
        // Neither given means "stop entirely", which is a real thing to want.
        // The query is already scoped to this user, so it cannot reach further.

        return response()->json(['data' => ['removed' => $query->delete()]]);
    }
}
