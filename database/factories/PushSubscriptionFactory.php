<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<PushSubscription> */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // Shaped like the real thing: Chrome hands out an FCM endpoint with
            // a long opaque token, which is why the column is 500 wide.
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/'.Str::random(152),
            'public_key' => Str::random(87),
            'auth_token' => Str::random(22),
            'device_label' => 'Android · Chrome',
            'last_used_at' => null,
        ];
    }
}
