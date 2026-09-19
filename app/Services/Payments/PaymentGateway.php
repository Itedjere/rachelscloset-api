<?php

namespace App\Services\Payments;

use App\Models\Payment;

/**
 * A payment provider, as the rest of the application sees it.
 *
 * Takes a Payment rather than an Order, unlike the BizyFarmers version this is
 * ported from. A subscription payment belongs to no order, and Section 14 needs
 * exactly this interface -- widening it later would mean editing every caller.
 */
interface PaymentGateway
{
    /** Machine name stored on the payment row. */
    public function name(): string;

    /** Starts a transaction and returns the URL to send the payer to. */
    public function initialise(Payment $payment, string $returnUrl, string $description): string;

    /**
     * Asks the provider whether this reference was paid, going to their API
     * rather than trusting anything the browser reports.
     */
    public function verify(string $reference): GatewayVerification;

    /**
     * Reverses a charge and returns the provider reference for the reversal.
     *
     * The amount is naira as a decimal string and may be less than the
     * original. Throws when the provider refuses, because a refund that did
     * not happen must never be recorded as one.
     */
    public function refund(Payment $payment, string $amount): string;

    /**
     * Confirms a webhook really came from the provider, and returns the
     * reference it concerns, or null when it is not a successful charge.
     */
    public function referenceFromWebhook(string $payload, array $headers): ?string;
}
