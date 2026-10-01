<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoundCustomerResource;
use App\Models\Order;
use App\Models\User;
use App\Rules\NigerianPhone;
use App\Services\Measurements\MeasurementAccess;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Adding a customer from the shop floor.
 *
 * The other half of the claim flow, and the half that was missing: claiming
 * has worked since Section 11, but nothing ever created a profile to claim, so
 * a tailor could not take an order from anybody who was not already here.
 *
 * What is created is a name and a phone number with no PIN. It cannot be
 * signed into and has consented to nothing -- MeasurementAccess already knows
 * what an unclaimed profile may and may not show, and the claim flow is how it
 * becomes hers. Nothing records which tailor added her, on purpose: every rule
 * about an unclaimed profile is per measurement set (`recorded_by`) or per
 * invite (`issued_by`), because two tailors may each meet the same walk-in.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly MeasurementAccess $access) {}

    /**
     * Her customers: the people she has actually made something for.
     *
     * A deliberate, narrow exception to "finding a customer is by exact phone
     * number, never a search". That rule exists so a tailor cannot build a
     * directory of OTHER people's clients; this list is scoped to people with
     * an order from her, whose names and numbers are already on her own order
     * pages. Nobody she has not sewn for can appear here.
     *
     * Paged, and searched on the server, because a busy shop's list grows
     * past what is worth sending to a phone at once -- and a search that only
     * filtered the page on screen would miss everybody on page two. The search
     * runs INSIDE the "has an order from her" condition, so it can never reach
     * beyond her own customers, which is also why a partial phone number is
     * allowed here (the last four digits are what people remember) when the
     * new-order lookup insists on the whole number.
     *
     * An order cancelled before anything happened does not count: that person
     * was never sewn for.
     */
    public function index(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 403);

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $term = trim($validated['q'] ?? '');
        $digits = preg_replace('/\D/', '', $term);

        $mine = fn ($query) => $query
            ->where('tailor_id', $tailor->id)
            ->where('status', '!=', Order::CANCELLED);

        $customers = User::query()
            ->where('role', User::ROLE_CUSTOMER)
            ->whereHas('customerOrders', $mine)
            ->when($term !== '', function ($query) use ($term, $digits) {
                $query->where(function ($inner) use ($term, $digits) {
                    $inner->where('name', 'like', '%'.$term.'%');

                    // Three digits or more, so "Ada 2" does not match every
                    // number with a 2 in it. A "+234…" prefix is reduced to
                    // the stored 0-form first.
                    if (strlen($digits) >= 3) {
                        $local = str_starts_with($digits, '234') ? '0'.substr($digits, 3) : $digits;
                        $inner->orWhere('phone', 'like', '%'.$local.'%');
                    }
                });
            })
            ->withCount(['customerOrders as orders_count' => $mine])
            ->withCount(['customerOrders as live_orders_count' => fn ($query) => $mine($query)
                ->whereIn('status', [Order::PENDING_PAYMENT, Order::IN_PROGRESS, Order::READY])])
            ->withMax(['customerOrders as last_order_at' => $mine], 'created_at')
            ->orderByDesc('last_order_at')
            // A tie-break, so two customers with an order in the same second
            // cannot swap pages between one request and the next.
            ->orderByDesc('users.id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return response()->json([
            'meta' => [
                'current_page' => $customers->currentPage(),
                'last_page' => $customers->lastPage(),
                'total' => $customers->total(),
            ],
            'data' => $customers->getCollection()->map(fn (User $customer) => [
                ...FoundCustomerResource::make($customer)->resolve($request),
                'orders_count' => (int) $customer->orders_count,
                // Orders still being made or waiting -- what she will want to
                // ring about first.
                'live_orders_count' => (int) $customer->live_orders_count,
                'last_order_at' => $customer->last_order_at,
                /*
                 * Asked of the same class the measurements page asks, so the
                 * button never leads to a "not found". A finished order does
                 * not keep granting access -- only a live one or her consent
                 * does -- and that is often the case on this list.
                 */
                'can_see_measurements' => $this->access->canSeeAnyOf($tailor, $customer),
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->isTailor(), 403);

        // Normalised BEFORE validating, so "0803 000 0009" and "+234803..."
        // are recognised as one number rather than becoming two customers.
        if (is_string($request->input('phone'))) {
            $request->merge([
                'phone' => NigerianPhone::normalise($request->input('phone')) ?? $request->input('phone'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', new NigerianPhone],
        ], [
            'name.required' => 'Write the name she goes by.',
        ]);

        /*
         * She is already here: hand her back rather than refusing.
         *
         * That is what the tailor wanted anyway, and it is what makes a double
         * tap on a slow connection harmless. Her name is NOT overwritten --
         * one tailor's spelling of it must not replace the one she chose.
         */
        if ($existing = $this->existing($validated['phone'])) {
            return response()->json(['data' => FoundCustomerResource::make($existing)->resolve($request)]);
        }

        try {
            $customer = User::create([
                'name' => trim($validated['name']),
                'phone' => $validated['phone'],
                'password' => null,
                'role' => User::ROLE_CUSTOMER,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two taps racing past the check above. The index is the rule;
            // the loser gets the row the winner made.
            $customer = $this->existing($validated['phone']);
        }

        return response()->json(['data' => FoundCustomerResource::make($customer)->resolve($request)], 201);
    }

    /**
     * The customer with this number, if there is one.
     *
     * A number held by a tailor or an admin is refused rather than returned.
     * An order needs a customer, and quietly treating somebody's staff
     * account as one is how an order ends up addressed to the wrong person.
     */
    private function existing(string $phone): ?User
    {
        $user = User::query()->where('phone', $phone)->first();

        if ($user && ! $user->isCustomer()) {
            throw ValidationException::withMessages([
                'phone' => 'That number is already on Rachel\'s Closet, but not as a customer.',
            ]);
        }

        return $user;
    }
}
