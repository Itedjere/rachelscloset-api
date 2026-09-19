<?php

namespace Database\Factories;

use App\Models\OrderStepPhoto;
use App\Services\FileAccess;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<OrderStepPhoto> */
class OrderStepPhotoFactory extends Factory
{
    protected $model = OrderStepPhoto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'path' => FileAccess::STEP_PHOTOS.'/'.Str::random(40).'.jpg',
        ];
    }
}
