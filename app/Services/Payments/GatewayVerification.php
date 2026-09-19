<?php

namespace App\Services\Payments;

/** What a provider says about one transaction. */
class GatewayVerification
{
    public function __construct(
        public readonly bool $successful,
        /** Amount the provider says was paid, in naira, as a decimal string. */
        public readonly string $amount,
        public readonly string $currency = 'NGN',
        /** Raw provider response, kept for debugging a disputed payment. */
        public readonly array $raw = [],
    ) {}

    public static function failed(array $raw = []): self
    {
        return new self(successful: false, amount: '0', raw: $raw);
    }
}
