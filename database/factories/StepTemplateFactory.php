<?php

namespace Database\Factories;

use App\Models\GarmentType;
use App\Models\StepTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<StepTemplate> */
class StepTemplateFactory extends Factory
{
    protected $model = StepTemplate::class;

    public function definition(): array
    {
        return [
            'garment_type_id' => GarmentType::factory(),
            'owner_id' => null,
            'name' => fake()->words(2, true),
        ];
    }
}
