<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Payments\TransferManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Where a tailor says which account to pay her into.
 *
 * Two steps on purpose. She picks a bank and types a number; we ask the bank
 * what that account is actually called and show her the name before anything
 * is saved. A transposed digit is then obvious while it is still free -- after
 * a transfer it is somebody else's money.
 */
class BankAccountController extends Controller
{
    public function __construct(private readonly TransferManager $transfers) {}

    /** The bank list, so she picks rather than types. */
    public function banks(): JsonResponse
    {
        abort_unless($this->transfers->configured(), 503, 'Payouts are not set up on this server.');

        return response()->json(['data' => $this->transfers->active()->banks()]);
    }

    /** What the bank says this account is called. Saves nothing. */
    public function resolve(Request $request): JsonResponse
    {
        $this->onlyTailors($request);

        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ], [
            'account_number.regex' => 'A Nigerian account number is ten digits.',
        ]);

        try {
            $account = $this->transfers->active()->resolveAccount(
                $validated['bank_code'],
                $validated['account_number'],
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'data' => ['account_name' => $account->accountName],
        ]);
    }

    /**
     * Save, after resolving again.
     *
     * Resolved a second time rather than trusting the name the client sends
     * back: otherwise the confirmation step is theatre and anybody could post
     * whatever name they liked against any account.
     */
    public function store(Request $request): JsonResponse
    {
        $profile = $this->onlyTailors($request);

        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:10'],
            'account_number' => ['required', 'string', 'regex:/^[0-9]{10}$/'],
        ]);

        try {
            $account = $this->transfers->active()->resolveAccount(
                $validated['bank_code'],
                $validated['account_number'],
            );
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $profile->forceFill([
            'bank_code' => $account->bankCode,
            'bank_account_number' => $account->accountNumber,
            // As the bank returned it, never as she typed it.
            'bank_account_name' => $account->accountName,
            'transfer_recipient' => $account->recipientCode(),
        ])->save();

        return response()->json(['data' => $this->shape($profile->fresh())]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->shape($this->onlyTailors($request))]);
    }

    private function onlyTailors(Request $request)
    {
        $user = $request->user();

        abort_unless($user->isTailor(), 403, 'Only a tailor is paid out.');

        $profile = $user->tailorProfile;

        abort_unless($profile, 422, 'Set up your shop profile first.');

        return $profile;
    }

    private function shape($profile): array
    {
        return [
            'bank_code' => $profile->bank_code,
            // Last four only. There is no reason to send a full account number
            // back down to a browser once it is stored.
            'account_number_tail' => $profile->bank_account_number
                ? substr($profile->bank_account_number, -4)
                : null,
            'account_name' => $profile->bank_account_name,
            'verified' => filled($profile->transfer_recipient),
        ];
    }
}
