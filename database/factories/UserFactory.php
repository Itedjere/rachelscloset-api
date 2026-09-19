<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected $model = User::class;

    /** The PIN every factory account signs in with, unless a test says otherwise. */
    public const PIN = '482917';

    private static ?string $hashedPin = null;

    public function definition(): array
    {
        // Hashed once per process. bcrypt is deliberately slow, and a suite
        // that creates a few hundred users would otherwise spend most of its
        // time hashing the same six digits over and over.
        self::$hashedPin ??= Hash::make(self::PIN);

        return [
            'name' => fake()->name(),
            'email' => null,
            'phone' => $this->uniqueNigerianPhone(),
            'password' => self::$hashedPin,
            'role' => User::ROLE_CUSTOMER,
            'status' => User::STATUS_ACTIVE,
        ];
    }

    public function customer(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_CUSTOMER]);
    }

    public function tailor(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_TAILOR]);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => User::ROLE_ADMIN]);
    }

    public function withEmail(): static
    {
        return $this->state(fn () => ['email' => fake()->unique()->safeEmail()]);
    }

    /**
     * A customer a tailor created from the shop floor, who has not yet claimed
     * the profile. No PIN, so nothing can sign in as her.
     */
    public function unclaimed(): static
    {
        return $this->state(fn () => ['password' => null]);
    }

    public function suspended(): static
    {
        return $this->state(fn () => [
            'status' => User::STATUS_SUSPENDED,
            'suspended_until' => now()->addDays(14),
        ]);
    }

    /** A real Nigerian mobile prefix, in the one form the app stores. */
    private function uniqueNigerianPhone(): string
    {
        $prefix = fake()->randomElement(['0803', '0805', '0807', '0810', '0813', '0816', '0703', '0706', '0813', '0901', '0902', '0906']);

        return $prefix.fake()->unique()->numerify('#######');
    }
}
