<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaimToken;
use App\Models\User;
use App\Notifications\PinWasReset;
use App\Rules\NigerianPhone;
use App\Rules\Pin;
use App\Services\Qr\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Getting back in after forgetting a PIN.
 *
 * This closes the hole the whole design would otherwise have. Sign-in is a
 * phone number and six digits; nothing here sends an SMS or requires an email
 * address, so the ordinary "reset link in your inbox" does not exist -- and
 * without a replacement, a tailor who forgets her PIN is locked out of her
 * business permanently.
 *
 * THE SAME THREE FREE CHANNELS as the claim flow, and free is not incidental:
 * a recovery route that costs money per use is one that gets switched off
 * when money is tight, which is precisely when people cannot afford to lose
 * their livelihood to a forgotten number.
 *
 *   - six digits read down an ordinary phone call;
 *   - a wa.me deep link from the admin's OWN WhatsApp (not the Business API);
 *   - a QR on screen, for the rare case they are in the same room.
 *
 * ADMIN-ISSUED, as the plan specifies, and that is a security decision rather
 * than an organisational one. A claim code opens a profile nobody has used
 * yet; a reset code opens an account with orders, money and measurements in
 * it. A tailor able to reset her own customer's PIN could take over that
 * account, and she is exactly the person with the motive.
 */
class PinResetController extends Controller
{
    public function __construct(private readonly QrCode $qr) {}

    /**
     * Whose account is this? Public, and deliberately thin.
     *
     * A first name and nothing else: enough to reassure somebody that the
     * link she was given is for her, and not enough to be worth guessing
     * tokens for.
     */
    public function preview(string $token): JsonResponse
    {
        $reset = ClaimToken::findByLinkToken($token);

        abort_unless(
            $reset && $reset->isUsable() && $reset->purpose === ClaimToken::PIN_RESET,
            404,
        );

        return response()->json(['data' => [
            'name' => $reset->user?->name,
        ]]);
    }

    /**
     * Choose a new one.
     *
     * By link or by spoken code, exactly as claiming works. Six digits are not
     * unique across the platform, so the code is only meaningful beside the
     * phone number it was issued for -- which is also the one thing the person
     * can supply without reading anything.
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required_without:code', 'nullable', 'string'],
            'code' => ['required_without:token', 'nullable', 'string', 'size:6'],
            'phone' => ['required_with:code', 'nullable', 'string', new NigerianPhone],
            'pin' => ['required', 'confirmed', new Pin],
        ]);

        $reset = isset($validated['token'])
            ? ClaimToken::findByLinkToken($validated['token'])
            : $this->byCode($validated['phone'], $validated['code']);

        // One message for every failure. Saying which part was wrong turns
        // this into a way of discovering whose numbers are registered here.
        $this->refuse($reset === null);
        $this->refuse(! $reset->isUsable());
        $this->refuse($reset->purpose !== ClaimToken::PIN_RESET);

        $user = $reset->user;

        $this->refuse($user === null);

        // The PIN must not be derivable from the phone number she is about to
        // sign in with. App\Rules\Pin needs the number to check that.
        $request->validate(['pin' => new Pin($user->phone)]);

        DB::transaction(function () use ($reset, $user, $request) {
            $user->forceFill(['password' => $request->string('pin')->value()])->save();

            $reset->markUsed();

            /*
             * EVERY OTHER SESSION DIES.
             *
             * Two cases, and both want this. If she forgot her PIN, the old
             * tokens are hers and worthless. If somebody else had got in --
             * which is a reason to reset -- leaving their session alive would
             * make the reset theatre. Tokens are deleted rather than left to
             * expire because Sanctum tokens here do not expire.
             */
            $user->tokens()->delete();

            /*
             * Any other outstanding reset for this person is spent too. A
             * second code lying around after the first was used is a second
             * chance to guess, for no benefit.
             */
            ClaimToken::query()
                ->where('user_id', $user->id)
                ->where('purpose', ClaimToken::PIN_RESET)
                ->whereNull('used_at')
                ->update(['expires_at' => now()->subSecond()]);
        });

        $user->notify(new PinWasReset);

        $token = $user->fresh()->createToken('pin-reset')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->only(['id', 'name', 'phone', 'role']),
        ]);
    }

    private function byCode(string $phone, string $code): ?ClaimToken
    {
        $user = User::query()->where('phone', NigerianPhone::normalise($phone))->first();

        if (! $user) {
            return null;
        }

        $reset = ClaimToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', ClaimToken::PIN_RESET)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        return $reset && $reset->codeMatches($code) ? $reset : null;
    }

    private function refuse(bool $condition): void
    {
        if ($condition) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right, or it has expired. Ask for a new one.',
            ]);
        }
    }
}
