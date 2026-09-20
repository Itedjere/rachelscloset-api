<?php

namespace Database\Factories;

use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PortfolioItem> */
class PortfolioItemFactory extends Factory
{
    protected $model = PortfolioItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tailor_id' => User::factory()->tailor(),
            'order_id' => null,
            'uploaded_by' => null,
            'path' => PortfolioItem::DIRECTORY.'/'.Str::random(40).'.jpg',
            'position' => 1,
        ];
    }
}
