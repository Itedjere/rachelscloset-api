<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Rules\NigerianPhone;
use App\Support\StoredFile;
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
 * A number that matches nobody is Section 11's problem: that is where a tailor
 * creates an unclaimed profile from the shop floor and invites her to claim it.
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

        return response()->json([
            'data' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'avatar_url' => StoredFile::url($customer->avatar_url),
                // An unclaimed profile has consented to nothing. Section 11
                // makes that mean something; surfacing it now stops a tailor
                // wondering why measurements are missing later.
                'claimed' => $customer->isClaimed(),
            ],
        ]);
    }
}
