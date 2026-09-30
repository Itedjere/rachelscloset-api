<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClaimToken;
use App\Models\TailorCustomerLink;
use App\Models\User;
use App\Rules\NigerianPhone;
use App\Rules\Pin;
use App\Services\Qr\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Taking over a profile a tailor created for you.
 *
 * Nothing in this project sends an SMS or requires an email address, so the
 * ordinary invite does not exist. What does exist is that the two people are
 * standing together when measurements are taken, which is why all three
 * channels are free and two of them send nothing at all: a QR code on the
 * tailor's screen, a wa.me link from her own WhatsApp, and six digits read
 * down an ordinary phone call.
 */
class ClaimController extends Controller
{
    public function __construct(private readonly QrCode $qr) {}

    /**
     * Issue an invitation. The tailor's side.
     *
     * The plaintext code and link exist only in this response. Re-issuing is
     * one tap and expires whatever came before, which is the right answer to
     * "I have lost the paper I wrote it on".
     */
    public function issue(Request $request, User $customer): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);
        abort_unless($customer->isCustomer(), 404);

        // Nothing to claim. Saying so plainly beats issuing a code that will
        // fail for a reason she cannot see.
        abort_if($customer->isClaimed(), 422, 'This customer has already set up her account.');

        $issued = ClaimToken::issue($customer, $tailor);

        $claimPage = rtrim((string) config('app.frontend_url'), '/').'/claim';
        $link = $claimPage.'/'.$issued['link_token'];

        return response()->json(['data' => [
            'code' => $issued['code'],
            /*
             * Where a spoken code is typed in. A code read down the phone
             * with no address beside it was a key with no door: nothing in
             * the app linked to this page, so the tailor's card now says the
             * address out loud along with the six digits.
             */
            'claim_page' => $claimPage,
            'link' => $link,
            'qr_svg' => $this->qr->svg($link),
            /*
             * A deep link into the tailor's OWN WhatsApp, prefilled. Not the
             * Business API -- it opens her app with a message ready to send,
             * so it costs nobody anything and comes from a number the
             * customer already knows.
             */
            'whatsapp_url' => $this->whatsappLink($customer, $link),
            'expires_at' => $issued['token']->expires_at,
        ]]);
    }

    /**
     * Whose profile is this? Public, and deliberately thin.
     *
     * Enough to reassure somebody who just scanned a stranger's QR code that
     * she is claiming the right record, and not enough to be worth guessing
     * tokens for. No phone number, no measurements, no order history.
     */
    public function preview(string $token): JsonResponse
    {
        $claim = ClaimToken::findByLinkToken($token);

        abort_unless($claim && $claim->isUsable() && $claim->purpose === ClaimToken::CLAIM, 404);

        $user = $claim->user;

        abort_if($user === null || $user->isClaimed(), 404);

        return response()->json(['data' => $this->previewOf($claim, $user)]);
    }

    /**
     * Is this spoken code right? Asked BEFORE she chooses a PIN.
     *
     * Without it the screen held two rows of six boxes at once -- the numbers
     * she was read and the numbers she is choosing -- and the only thing
     * telling them apart was a label, on a platform built for people who read
     * poorly. Now she types the code, is told it is right and whose account
     * it is, and only then sees the PIN boxes.
     *
     * Consumes nothing: the claim itself re-checks everything. It shares the
     * `claim` rate limiter with the claim route, so it is not a second budget
     * of guesses, and it refuses with the same single message for the same
     * reason.
     */
    public function check(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'phone' => ['required', 'string', new NigerianPhone],
        ]);

        [$claim, $user] = $this->resolve($this->byCode($validated['phone'], $validated['code']));

        return response()->json(['data' => $this->previewOf($claim, $user)]);
    }

    /**
     * Claim it, by link or by spoken code.
     *
     * The link identifies the row on its own. The code does not -- six digits
     * are not unique across the platform -- so it is only meaningful together
     * with the phone number it was issued for, which is also the thing the
     * customer can supply without reading anything.
     */
    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required_without:code', 'nullable', 'string'],
            'code' => ['required_without:token', 'nullable', 'string', 'size:6'],
            'phone' => ['required_with:code', 'nullable', 'string', new NigerianPhone],
            'pin' => ['required', 'confirmed', new Pin],
        ]);

        [$claim, $user] = $this->resolve(isset($validated['token'])
            ? ClaimToken::findByLinkToken($validated['token'])
            : $this->byCode($validated['phone'], $validated['code']));

        // The PIN must not be derivable from the phone number printed on the
        // screen beside it -- App\Rules\Pin already refuses that, and it
        // needs the number to do it.
        $request->validate(['pin' => new Pin($user->phone)]);

        DB::transaction(function () use ($claim, $user) {
            $user->forceFill(['password' => request()->string('pin')->value()])->save();

            $claim->markUsed();

            /*
             * Claiming through a tailor's invitation grants that tailor a
             * link.
             *
             * This is the moment the plan calls "cross-tailor history begins
             * when a real person consents". She is claiming a record that
             * this tailor made, having been handed the code by her, and the
             * claim screen says in as many words that it lets her keep
             * seeing the measurements. It is one tap to take back afterwards,
             * from a screen that lists everyone who can see.
             */
            if ($claim->issued_by) {
                TailorCustomerLink::firstOrCreate([
                    'tailor_id' => $claim->issued_by,
                    'customer_id' => $user->id,
                ])->grant();
            }
        });

        $token = $user->fresh()->createToken('claim')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->only(['id', 'name', 'phone', 'role']),
        ]);
    }

    /**
     * The claim and its customer, or the one refusal.
     *
     * Shared by the check and the claim so the two can never disagree about
     * what counts as a usable code. One message for every failure: saying
     * which part was wrong turns this into a way of discovering whose numbers
     * are registered here.
     *
     * @return array{0: ClaimToken, 1: User}
     */
    private function resolve(?ClaimToken $claim): array
    {
        $this->refuse($claim === null);
        $this->refuse(! $claim->isUsable());
        $this->refuse($claim->purpose !== ClaimToken::CLAIM);

        $user = $claim->user;

        $this->refuse($user === null || $user->isClaimed());

        return [$claim, $user];
    }

    /** Enough to reassure her it is the right record, and nothing worth guessing for. */
    private function previewOf(ClaimToken $claim, User $user): array
    {
        return [
            'name' => $user->name,
            'invited_by' => $claim->issuedBy?->name,
            'invited_by_business' => $claim->issuedBy?->tailorProfile?->business_name,
        ];
    }

    private function byCode(string $phone, string $code): ?ClaimToken
    {
        $user = User::query()->where('phone', NigerianPhone::normalise($phone))->first();

        if (! $user) {
            return null;
        }

        $claim = ClaimToken::query()
            ->where('user_id', $user->id)
            ->where('purpose', ClaimToken::CLAIM)
            ->whereNull('used_at')
            ->latest('id')
            ->first();

        return $claim && $claim->codeMatches($code) ? $claim : null;
    }

    private function refuse(bool $condition): void
    {
        if ($condition) {
            throw ValidationException::withMessages([
                'code' => 'That code is not right, or it has expired. Ask your tailor for a new one.',
            ]);
        }
    }

    private function whatsappLink(User $customer, string $link): string
    {
        $message = "Hello {$customer->name}, here is your Rachel's Closet account. "
            ."Open this to finish setting it up: {$link}";

        return 'https://wa.me/?text='.rawurlencode($message);
    }
}
