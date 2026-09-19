<?php

namespace Database\Factories;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_id' => User::factory()->customer(),
            'tailor_id' => User::factory()->tailor(),
            'garment_type_id' => GarmentType::factory(),
            'description' => fake()->sentence(4),
            'amount' => '25000.00',
            'deposit_amount' => '0.00',
            'escrow' => false,
            'status' => Order::PENDING_PAYMENT,
        ];
    }

    public function escrow(): static
    {
        return $this->state(fn () => ['escrow' => true]);
    }

    public function withDeposit(string $amount = '10000.00'): static
    {
        return $this->state(fn () => ['deposit_amount' => $amount]);
    }

    public function inProgress(): static
    {
        return $this->state(fn () => ['status' => Order::IN_PROGRESS]);
    }

    public function ready(): static
    {
        return $this->state(fn () => [
            'status' => Order::READY,
            'ready_at' => now(),
            'collection_deadline' => now()->addDays(14)->toDateString(),
        ]);
    }
}
