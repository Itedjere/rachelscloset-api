<?php

namespace Database\Seeders;

use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /** The PIN the seeded demo accounts sign in with. Local development only. */
    public const DEMO_PIN = '482917';

    public function run(): void
    {
        $this->call(PlatformSettingSeeder::class);
        $this->call(StepLibrarySeeder::class);

        // Admins cannot sign themselves up, so one has to be seeded.
        User::firstOrCreate(
            ['phone' => '08030000001'],
            [
                'name' => 'Rachel (admin)',
                'email' => 'admin@rachelscloset.com.ng',
                'password' => Hash::make(self::DEMO_PIN),
                'role' => User::ROLE_ADMIN,
            ],
        );

        $tailor = User::firstOrCreate(
            ['phone' => '08030000002'],
            [
                'name' => 'Ngozi Okeke',
                'password' => Hash::make(self::DEMO_PIN),
                'role' => User::ROLE_TAILOR,
            ],
        );

        TailorProfile::firstOrCreate(
            ['user_id' => $tailor->id],
            [
                'business_name' => 'Mama Ngozi Couture',
                'slug' => TailorProfile::slugFor('Mama Ngozi Couture'),
                'bio' => 'Gowns, kaftans and agbada. Twelve years on the same street.',
                'location' => 'Rumuodara',
                'state' => 'Rivers',
                'whatsapp_phone' => '08030000002',
            ],
        );

        // No email on purpose: this is the account shape the platform is built
        // around, and it should be the one being tested by default.
        User::firstOrCreate(
            ['phone' => '08030000003'],
            [
                'name' => 'Amaka Eze',
                'password' => Hash::make(self::DEMO_PIN),
                'role' => User::ROLE_CUSTOMER,
            ],
        );
    }
}
