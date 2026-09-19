<?php

namespace App\Services\Payments;

use RuntimeException;

/**
 * Picks the gateway.
 *
 * One provider today, so this looks like ceremony. It is not: it is the single
 * place that decides whether real money or the sandbox is in play, and the
 * place that refuses to let the sandbox run anywhere but locally.
 */
class PaymentGatewayManager
{
    public function __construct(
        private readonly FlutterwaveGateway $flutterwave,
        private readonly SandboxGateway $sandbox,
    ) {}

    public function active(): PaymentGateway
    {
        if (! $this->sandboxEnabled()) {
            return $this->flutterwave;
        }

        return $this->sandbox;
    }

    /**
     * Whether the sandbox is in play.
     *
     * Two conditions, both required. The flag says somebody asked for it; the
     * environment check means a `.env` copied to the server by accident cannot
     * turn the webhook endpoint into one that accepts unsigned instructions
     * from anybody. Getting this wrong is not a bug that shows up in testing --
     * everything would keep working, for free, for whoever noticed.
     */
    public function sandboxEnabled(): bool
    {
        return config('services.flutterwave.sandbox') && app()->environment('local', 'testing');
    }

    /** The gateway a stored payment was made through. */
    public function for(string $provider): PaymentGateway
    {
        if ($provider !== 'flutterwave') {
            throw new RuntimeException("No gateway for provider [{$provider}].");
        }

        return $this->active();
    }

    /** Whether real credentials are present, so the app can say so plainly. */
    public function configured(): bool
    {
        return $this->sandboxEnabled() || filled(config('services.flutterwave.secret'));
    }
}
