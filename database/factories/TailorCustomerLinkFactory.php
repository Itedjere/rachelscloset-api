<?php

namespace Database\Factories;

use App\Models\TailorCustomerLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TailorCustomerLink> */
class TailorCustomerLinkFactory extends Factory
{
    protected $model = TailorCustomerLink::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tailor_id' => User::factory()->tailor(),
            'customer_id' => User::factory(),
            'status' => TailorCustomerLink::GRANTED,
            'granted_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => [
            'status' => TailorCustomerLink::REVOKED,
            'revoked_at' => now(),
        ]);
    }
}
