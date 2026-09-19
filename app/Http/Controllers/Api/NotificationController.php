<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Support\NotificationCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class NotificationController extends Controller
{
    /** The signed-in user's notifications, newest first, with the unread count. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'unread' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = $request->user()->appNotifications();

        $notifications = (clone $query)
            ->when($validated['unread'] ?? false, fn ($builder) => $builder->unread())
            ->latest()
            ->paginate($validated['per_page'] ?? 20);

        return NotificationResource::collection($notifications)->additional([
            'unread_count' => (clone $query)->unread()->count(),
        ]);
    }

    /** Just the badge number, polled by the header. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => $request->user()->appNotifications()->unread()->count()],
        ]);
    }

    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 404);

        // Already-read stays as it was, so the original time is not overwritten.
        if (! $notification->read_at) {
            $notification->update(['read_at' => now()]);
        }

        return response()->json([
            'data' => NotificationResource::make($notification->fresh())->resolve($request),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()->appNotifications()->unread()->update(['read_at' => now()]);

        return response()->json(['data' => ['unread_count' => 0]]);
    }

    /**
     * The switches, and what each one governs.
     *
     * Shipped with the labels rather than the React app holding its own copy,
     * so adding a group is one change in one file. The hints matter here more
     * than usual: somebody deciding what to switch off is reading, which is the
     * thing this platform assumes people find hard.
     */
    public function preferences(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'groups' => collect(NotificationCategories::OPTIONAL)
                    ->map(fn (array $group, string $key) => $group + ['key' => $key])
                    ->values(),
                'preferences' => $request->user()->pushPreferences(),
            ],
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $groups = array_keys(NotificationCategories::OPTIONAL);

        $rules = ['preferences' => ['required', 'array']];

        foreach ($groups as $group) {
            $rules["preferences.{$group}"] = ['required', 'boolean'];
        }

        $validated = $request->validate($rules);

        $user = $request->user();

        // Stored whole rather than merged, so a group switched back on is
        // written as `true` instead of quietly relying on the absent-means-on
        // default -- which would read identically until the defaults changed.
        $user->forceFill([
            'notification_preferences' => array_map(
                fn ($value) => (bool) $value,
                $validated['preferences'],
            ),
        ])->save();

        return response()->json(['data' => ['preferences' => $user->pushPreferences()]]);
    }
}
