<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionTerm;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Subscriptions\StartTerm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Buying days.
 *
 * She buys 30 or 365 of them with one ordinary charge; nothing here ever
 * charges her again. Renewing is the same act as subscribing, which is why
 * there is one endpoint rather than a subscribe and a renew.
 */
class SubscriptionController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly StartTerm $terms,
    ) {}

    /** Where she stands, and what the two plans cost today. */
    public function show(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $subscription = Subscription::forTailor($tailor);

        return response()->json(['data' => [
            // The label, freshly recomputed, for wording only.
            'status' => $subscription->status,
            'listed' => $subscription->covers(),
            'current_period_end' => $subscription->current_period_end,
            'grace_ends_at' => $subscription->grace_ends_at,
            'days_remaining' => $subscription->daysRemaining(),
            'in_grace' => $subscription->status === Subscription::GRACE,

            'plans' => collect([Subscription::MONTHLY, Subscription::YEARLY])
                ->map(fn (string $plan) => [
                    'plan' => $plan,
                    ...$this->terms->plan($plan),
                ])->values(),

            'grace_days' => (int) PlatformSetting::get(PlatformSetting::SUBSCRIPTION_GRACE_DAYS, 7),

            'terms' => $subscription->terms()->latest('starts_at')->take(12)->get()
                ->map(fn (SubscriptionTerm $term) => [
                    'id' => $term->id,
                    'plan' => $term->plan,
                    'days' => $term->days,
                    'amount' => (string) $term->amount,
                    'starts_at' => $term->starts_at,
                    'ends_at' => $term->ends_at,
                    'granted' => $term->payment_id === null,
                    'note' => $term->note,
                ]),
        ]]);
    }

    /**
     * Start paying for a term.
     *
     * The row is written before the provider hears about it, for the same
     * reason as an order payment: a payment arriving for a reference we never
     * issued is one we refuse to apply, and that is only possible if we issue
     * it first.
     */
    public function pay(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $validated = $request->validate([
            'plan' => ['required', Rule::in([Subscription::MONTHLY, Subscription::YEARLY])],
        ]);

        abort_unless($this->gateways->configured(), 503, 'Payments are not set up on this server.');

        $subscription = Subscription::forTailor($tailor);
        $plan = $this->terms->plan($validated['plan']);

        /*
         * Deliberately no "you are already subscribed" refusal. Buying while
         * a term is running is renewing early, the days stack onto the end,
         * and a tailor who wants to pay a year ahead in January should not be
         * told to come back in December.
         */
        $payment = Payment::create([
            'purpose' => Payment::PURPOSE_SUBSCRIPTION,
            'subscription_id' => $subscription->id,
            'plan' => $validated['plan'],
            'payer_id' => $tailor->id,
            'provider' => $this->gateways->active()->name(),
            // Ours, and legible in the provider's dashboard without a lookup.
            'provider_reference' => 'RC-SUB-'.Str::upper(Str::random(8)),
            'amount' => $plan['price'],
            'status' => Payment::PENDING,
        ]);

        try {
            $link = $this->gateways->active()->initialise(
                $payment->load('payer'),
                rtrim((string) config('app.frontend_url'), '/').'/subscription/paid',
                ucfirst($validated['plan']).' listing on Rachel\'s Closet — '.$plan['days'].' days',
            );
        } catch (RuntimeException $exception) {
            // The row stays, marked failed, so the attempt is visible when
            // somebody asks why nothing happened.
            $payment->update(['status' => Payment::FAILED]);

            return response()->json(['message' => $exception->getMessage()], 502);
        }

        return response()->json(['data' => [
            'reference' => $payment->provider_reference,
            'amount' => (string) $payment->amount,
            'link' => $link,
        ]]);
    }
}
