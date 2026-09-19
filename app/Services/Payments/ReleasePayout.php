<?php

namespace App\Services\Payments;

use App\Models\Payout;
use App\Notifications\PayoutReleased;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sending a tailor her money.
 *
 * The one place a payout is released, so the rules cannot drift, and the row
 * is locked so two attempts cannot both send.
 *
 * ESCROW RELEASE IS INDEPENDENT OF SUBSCRIPTION STATUS. A lapsed subscription
 * hides a tailor from the directory and nothing else. Money owed is owed, and
 * withholding it over a bill is the kind of thing that ends a platform.
 */
class ReleasePayout
{
    public function __construct(private readonly TransferManager $transfers) {}

    /**
     * Attempts one payout. Never throws for an ordinary problem -- a tailor
     * with no bank details yet is a normal state, not an error, and the caller
     * is usually finishing an order rather than asking about money.
     */
    public function handle(Payout $payout): Payout
    {
        return DB::transaction(function () use ($payout) {
            /** @var Payout $payout */
            $payout = Payout::query()->whereKey($payout->id)->lockForUpdate()->first();

            // Already sent, by whichever of the sweep or a manual release won.
            if ($payout->isReleased()) {
                return $payout;
            }

            if (bccomp((string) $payout->net_amount, '0', 2) !== 1) {
                // Fully refunded. Nothing to send, and nothing wrong either.
                $payout->forceFill([
                    'status' => Payout::RELEASED,
                    'released_at' => now(),
                    'failure_reason' => null,
                ])->save();

                return $payout;
            }

            $profile = $payout->tailor->tailorProfile;

            if (! $profile || blank($profile->transfer_recipient)) {
                /*
                 * She has not added a verified account yet. The money stays
                 * recorded as owed and the payout stays pending -- completing
                 * an order must never fail because somebody has not finished
                 * a form.
                 */
                $payout->forceFill([
                    'status' => Payout::PENDING,
                    'failure_reason' => 'No verified bank account on file yet.',
                ])->save();

                return $payout;
            }

            try {
                $reference = $this->transfers->active()->transfer($payout, $profile);
            } catch (RuntimeException $exception) {
                /*
                 * Failed, not pending: something was wrong with the attempt
                 * rather than missing from the profile. It can be retried, and
                 * the reason is kept so somebody can see why without reading
                 * the log.
                 */
                Log::warning('Payout transfer refused', [
                    'payout_id' => $payout->id,
                    'message' => $exception->getMessage(),
                ]);

                $payout->forceFill([
                    'status' => Payout::FAILED,
                    'failure_reason' => $exception->getMessage(),
                ])->save();

                return $payout;
            }

            $payout->forceFill([
                'status' => Payout::RELEASED,
                'provider' => 'flutterwave',
                'provider_transfer_reference' => $reference,
                'released_at' => now(),
                'failure_reason' => null,
            ])->save();

            $payout->tailor->notify(new PayoutReleased($payout->fresh()->load('order')));

            return $payout->fresh();
        });
    }
}
