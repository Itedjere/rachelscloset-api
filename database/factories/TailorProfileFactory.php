<?php

namespace Database\Factories;

use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<TailorProfile> */
class TailorProfileFactory extends Factory
{
    protected $model = TailorProfile::class;

    public function definition(): array
    {
        $name = fake()->randomElement(['Golden Needle', 'Mama Ngozi Couture', 'Adire House', 'Royal Stitches', 'Anointed Fingers'])
            .' '.fake()->unique()->numberBetween(1, 9999);

        return [
            'user_id' => User::factory()->tailor(),
            'business_name' => $name,
            'slug' => Str::slug($name),
            'bio' => fake()->sentence(12),
            'location' => fake()->randomElement(['Ikeja', 'Rumuodara', 'Wuse', 'Aba', 'Surulere']),
            'state' => fake()->randomElement(['Lagos', 'Rivers', 'FCT', 'Abia', 'Oyo']),
        ];
    }
}
