<?php

namespace App\Services\Payments;

/**
 * Picks the transfer gateway.
 *
 * Mirrors PaymentGatewayManager, and refuses the sandbox outside local for the
 * same reason: a server that believes it has sent money it has not sent is
 * worse than one that refuses to send any.
 */
class TransferManager
{
    public function __construct(
        private readonly FlutterwaveTransfers $flutterwave,
        private readonly SandboxTransfers $sandbox,
    ) {}

    public function active(): TransferGateway
    {
        return $this->sandboxEnabled() ? $this->sandbox : $this->flutterwave;
    }

    public function sandboxEnabled(): bool
    {
        return config('services.flutterwave.sandbox') && app()->environment('local', 'testing');
    }

    public function configured(): bool
    {
        return $this->sandboxEnabled() || filled(config('services.flutterwave.secret'));
    }
}
