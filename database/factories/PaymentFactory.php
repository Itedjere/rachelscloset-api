<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $order = Order::factory();

        return [
            'purpose' => Payment::PURPOSE_ORDER,
            'order_id' => $order,
            'payer_id' => fn (array $attributes) => Order::find($attributes['order_id'])?->customer_id,
            'provider' => 'flutterwave',
            'provider_reference' => 'RC-TEST-'.Str::upper(Str::random(10)),
            'amount' => '25000.00',
            'status' => Payment::PENDING,
        ];
    }

    public function successful(): static
    {
        return $this->state(fn () => ['status' => Payment::SUCCESSFUL, 'paid_at' => now()]);
    }
}
