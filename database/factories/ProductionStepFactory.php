<?php

namespace Database\Factories;

use App\Models\ProductionStep;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ProductionStep> */
class ProductionStepFactory extends Factory
{
    protected $model = ProductionStep::class;

    public function definition(): array
    {
        return [
            'label' => ucfirst(fake()->unique()->words(2, true)),
            'instructions' => fake()->sentence(),
        ];
    }

    public function withVoiceNote(string $path = 'step-voice-notes/note.webm'): static
    {
        return $this->state(fn () => ['voice_note_url' => $path]);
    }

    public function retired(): static
    {
        return $this->state(fn () => ['retired_at' => now()]);
    }
}
