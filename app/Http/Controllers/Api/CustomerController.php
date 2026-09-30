<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoundCustomerResource;
use App\Models\User;
use App\Rules\NigerianPhone;
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
