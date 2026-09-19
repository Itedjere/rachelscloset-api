<?php

namespace Database\Factories;

use App\Models\GarmentType;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GarmentType> */
class GarmentTypeFactory extends Factory
{
    protected $model = GarmentType::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'position' => 0,
        ];
    }

    public function retired(): static
    {
        return $this->state(fn () => ['retired_at' => now()]);
    }
}
