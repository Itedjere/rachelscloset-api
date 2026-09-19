<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'],
                'password' => $data['pin'],
                'role' => $data['role'],
            ]);

            if ($user->isTailor()) {
                TailorProfile::create([
                    'user_id' => $user->id,
                    'business_name' => $data['business_name'],
                    'slug' => TailorProfile::slugFor($data['business_name']),
                    'location' => $data['location'],
                    'state' => $data['state'] ?? null,
                ]);
            }

            return $user;
        });

        return $this->tokenResponse($user, $request->input('device_name'), 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = User::findByIdentifier($request->input('identifier'));

        /*
         * One message for every kind of failure -- no such account, wrong PIN,
         * a profile a tailor created that nobody has claimed. Saying which
         * would let somebody discover whose numbers are registered here simply
         * by trying them, and this is a platform where the username is a phone
         * number and phone numbers are guessable.
         */
        if (! $user || ! $user->isClaimed() || ! Hash::check($request->input('pin'), $user->password)) {
            throw ValidationException::withMessages([
                'identifier' => 'That phone number and PIN do not match an account.',
            ]);
        }

        // Let a fixed-term suspension lapse on use, the same as the middleware.
        if ($user->suspensionHasExpired()) {
            $user->forceFill(['status' => User::STATUS_ACTIVE, 'suspended_until' => null])->save();
        }

        if (! $user->isActive()) {
            throw ValidationException::withMessages([
                'identifier' => $user->suspended_until
                    ? 'Your account is suspended until '.$user->suspended_until->format('j F Y').'.'
                    : 'Your account is suspended.',
            ]);
        }

        return $this->tokenResponse($user, $request->input('device_name'));
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->loadMissing('tailorProfile')),
        ]);
    }

    /** Signs out this device only, leaving her other sessions alone. */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    private function tokenResponse(User $user, ?string $deviceName, int $status = 200): JsonResponse
    {
        $token = $user->createToken($deviceName ?: 'rachelscloset-web')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UserResource($user->loadMissing('tailorProfile')),
        ], $status);
    }
}
