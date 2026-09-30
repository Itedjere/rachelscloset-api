<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\FoundCustomerResource;
use App\Models\User;
use App\Rules\NigerianPhone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Finding the customer who is standing in front of you.
 *
 * By exact phone number only -- deliberately not a search.
 *
 * A tailor being able to browse or name-search every customer on the platform
 * would be a directory of other people's clients, which is not something she
 * needs and not something they agreed to. The phone number is the username
 * here; the customer is in the shop and reads it out. Anything less specific
 * gives away more than the job requires.
 *
 * A number that matches nobody is where CustomerController::store comes in:
 * the tailor adds her from the shop floor and invites her to claim it.
 */
class CustomerLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($request->user()->isTailor(), 403);

        $validated = $request->validate([
            'phone' => ['required', 'string', new NigerianPhone],
        ]);

        // Normalised before the lookup, so 0803..., +234803... and a number
        // typed with spaces all find the row stored the one canonical way.
        $phone = NigerianPhone::normalise($validated['phone']);

        $customer = User::query()
            ->where('phone', $phone)
            ->where('role', User::ROLE_CUSTOMER)
            ->first();

        if (! $customer) {
            return response()->json([
                'message' => 'Nobody on the platform has that number yet.',
            ], 404);
        }

        return response()->json(['data' => FoundCustomerResource::make($customer)->resolve($request)]);
    }
}
