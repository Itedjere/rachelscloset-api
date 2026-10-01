<?php

namespace Database\Factories;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Notification> */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'step_completed',
            'payload' => [
                'title' => 'Rachels Closet',
                'message' => 'Your blouse has moved on a stage.',
                'url' => '/orders/1',
            ],
            'read_at' => null,
            'created_at' => now(),
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()]);
    }

    /** Old enough for the prune to take it, measured from whichever date applies. */
    public function aged(int $days): static
    {
        return $this->state(fn (array $attributes) => [
            'created_at' => now()->subDays($days),
            'read_at' => $attributes['read_at'] ? now()->subDays($days) : null,
        ]);
    }
}
