<?php

namespace Database\Factories;

use App\Models\MeasurementSet;
use App\Models\User;
use App\Services\FileAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MeasurementSet> */
class MeasurementSetFactory extends Factory
{
    protected $model = MeasurementSet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'recorded_by' => User::factory()->tailor(),
            'photo_url' => FileAccess::MEASUREMENTS.'/'.Str::random(40).'.jpg',
            'label' => $this->faker->randomElement(['Wedding', 'Everyday', 'Aso-ebi']),
            'taken_on' => now()->toDateString(),
        ];
    }
}
