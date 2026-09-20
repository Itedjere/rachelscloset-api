<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\User;
use App\Notifications\AccountReinstated;
use App\Notifications\AccountSuspended;
use App\Rules\NigerianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

/**
 * People, and the one thing an admin can do to them.
 *
 * Suspension has been enforced since Section 1 -- `EnsureUserIsActive` cuts a
 * live session rather than waiting for the next sign-in, and a fixed term
 * lapses on use rather than on a schedule -- but nothing has ever been able to
 * start one. This is that missing half, and it is deliberately the only power
 * here: no editing somebody's name, no reading their measurements, no
 * changing a phone number that is also a username.
 *
 * A DELIBERATE WIDENING of the ordinary scoping, like the admin order screens.
 * Everywhere else on this platform, listing people is refused precisely so a
 * tailor cannot build a directory of other people's customers. An admin
 * running the business genuinely has to be able to find somebody who has
 * written in, so this lists everybody -- and it is the only place that does.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', Rule::in([User::ROLE_CUSTOMER, User::ROLE_TAILOR, User::ROLE_ADMIN])],
            'status' => ['nullable', Rule::in([User::STATUS_ACTIVE, User::STATUS_SUSPENDED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $term = $validated['q'] ?? null;

        /** @var LengthAwarePaginator<int, User> $users */
        $users = User::query()
            ->with('tailorProfile')
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($validated['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($term, function ($q) use ($term) {
                /*
                 * A phone number is matched exactly, after normalising, so
                 * "0803...", "+234803..." and a number typed with spaces all
                 * find the one canonical row. Anything else is a name search.
                 */
                $phone = NigerianPhone::normalise($term);

                $q->where(function ($inner) use ($term, $phone) {
                    $inner->where('name', 'like', '%'.$term.'%');

                    if ($phone) {
                        $inner->orWhere('phone', $phone);
                    }
                });
            })
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'data' => $users->getCollection()->map(fn (User $user) => $this->shape($user)),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Suspend somebody, for a fixed number of days by default.
     *
     * Fixed-term because an indefinite suspension is one nobody ever gets
     * round to lifting, and the middleware already knows how to let one lapse
     * on its own. The default length is a setting.
     */
    public function suspend(Request $request, User $user): JsonResponse
    {
        $admin = $request->user();

        // Not yourself, and not another admin. The first is a way to lock
        // everybody out of the platform by accident; the second is a fight
        // between colleagues that a button should not adjudicate.
        abort_if($user->id === $admin->id, 422, 'You cannot suspend yourself.');
        abort_if($user->isAdmin(), 422, 'An admin cannot be suspended from here.');

        $validated = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $days = $validated['days']
            ?? (int) PlatformSetting::get(PlatformSetting::DEFAULT_SUSPENSION_DAYS, 14);

        $user->forceFill([
            'status' => User::STATUS_SUSPENDED,
            'suspended_until' => now()->addDays($days),
        ])->save();

        /*
         * Told, and it cannot be switched off: NotificationCategories puts
         * this under ACCOUNT for exactly this reason. Somebody who cannot use
         * the platform has to be able to find out why.
         */
        $user->notify(new AccountSuspended($user->fresh(), $validated['reason'] ?? null));

        return response()->json(['data' => $this->shape($user->fresh())]);
    }

    public function reinstate(Request $request, User $user): JsonResponse
    {
        abort_unless($user->status === User::STATUS_SUSPENDED, 422, 'That account is not suspended.');

        $user->forceFill([
            'status' => User::STATUS_ACTIVE,
            'suspended_until' => null,
        ])->save();

        $user->notify(new AccountReinstated($user->fresh()));

        return response()->json(['data' => $this->shape($user->fresh())]);
    }

    /** @return array<string, mixed> */
    private function shape(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'phone' => $user->phone,
            'role' => $user->role,
            'status' => $user->status,
            'suspended_until' => $user->suspended_until,
            'claimed' => $user->isClaimed(),
            'avatar_url' => $user->avatar_url,
            'business_name' => $user->tailorProfile?->business_name,
            'slug' => $user->tailorProfile?->slug,
            'created_at' => $user->created_at,
        ];
    }
}
