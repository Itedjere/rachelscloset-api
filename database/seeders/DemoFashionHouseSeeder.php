<?php

namespace Database\Seeders;

use App\Models\GarmentType;
use App\Models\Order;
use App\Models\PortfolioItem;
use App\Models\Review;
use App\Models\Subscription;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\Reviews\RecalculateTailorRating;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Fifty tailors, so the Fashion House can be seen full.
 *
 * DEMO DATA, LOCAL ONLY. It refuses to run anywhere but `local`: fifty
 * invented shops on a live directory would be the platform lying to the
 * people it exists to help. Not called from DatabaseSeeder -- run it on
 * purpose:
 *
 *     php artisan db:seed --class=DemoFashionHouseSeeder
 *
 * Every account it makes has a phone beginning 0809 9, so it is easy to find
 * and remove. Covers are the Nigerian fashion photographs already chosen for
 * the landing page (config/gallery.php), downloaded once into the same private
 * `portfolio/` storage a real upload uses, so the cards go through the real
 * pipeline. A few tailors deliberately get no photograph, to show the
 * monogram; about a third have nothing on the table, to show the pale dot.
 */
class DemoFashionHouseSeeder extends Seeder
{
    private const PHONE_PREFIX = '080999';

    private const PIN = '482917';

    private const PHOTOS = [
        39054376, 38500516, 39289632, 11482148, 11645426, 38632982, 29997421,
        31951217, 37810843, 37036207, 33624748, 38395864, 29553408, 12447951,
        31884483, 34214461, 34550152, 39134398, 12659984,
    ];

    /** [shop, owner, area, state] */
    private const TAILORS = [
        ['Adaeze Bridal', 'Adaeze Okafor', 'Lekki', 'Lagos'],
        ['House of Amaka', 'Amaka Nwosu', 'Wuse II', 'FCT'],
        ['Bello & Sons', 'Musa Bello', 'Sabon Gari', 'Kano'],
        ['Chidi Tailoring', 'Chidi Eze', 'Rumuola', 'Rivers'],
        ['Ola Agbada Studio', 'Olamide Adeyemi', 'Bodija', 'Oyo'],
        ['Ìrẹ̀lẹ̀ Couture', 'Funmilayo Ajayi', 'Ikeja', 'Lagos'],
        ['Golden Thimble', 'Ngozi Obi', 'Independence Layout', 'Enugu'],
        ['Kaftan Kingdom', 'Ibrahim Sani', 'Barnawa', 'Kaduna'],
        ['Ankara Avenue', 'Blessing Udo', 'Ewet Housing', 'Akwa Ibom'],
        ['Lace & Grace', 'Grace Effiong', 'Calabar South', 'Cross River'],
        ['Royal Stitches', 'Emeka Nnadi', 'Owerri North', 'Imo'],
        ['Mama Tobi Designs', 'Tobi Ogunleye', 'Surulere', 'Lagos'],
        ['The Aso-oke Room', 'Kehinde Alabi', 'Oke-Ado', 'Oyo'],
        ['Zainab Fashion House', 'Zainab Yusuf', 'Garki', 'FCT'],
        ['Silk Road Tailors', 'Uche Okonkwo', 'Onitsha', 'Anambra'],
        ['Needle & Pride', 'Efe Okoro', 'Warri', 'Delta'],
        ['Bisi Couture', 'Bisi Adebayo', 'Abeokuta', 'Ogun'],
        ['Ugo Menswear', 'Ugochukwu Ibe', 'Aba', 'Abia'],
        ['Halima Hand-Sewn', 'Halima Abubakar', 'Jimeta', 'Adamawa'],
        ['Patience Bespoke', 'Patience Ojo', 'Akure', 'Ondo'],
        ['Seun Senator Wear', 'Seun Bamidele', 'Yaba', 'Lagos'],
        ['Benin Bronze Couture', 'Osas Igbinedion', 'GRA', 'Edo'],
        ['Jos Plateau Threads', 'Dakup Pam', 'Rayfield', 'Plateau'],
        ['Ilorin Ileke', 'Aishat Abdulsalam', 'Tanke', 'Kwara'],
        ['The Wrapper Studio', 'Chinwe Agu', 'Abakaliki', 'Ebonyi'],
        ['Osogbo Adire House', 'Adunni Ogundipe', 'Oke-Fia', 'Osun'],
        ['Port Harcourt Tailoring Co.', 'Tamuno Briggs', 'Trans-Amadi', 'Rivers'],
        ['Amina Abaya & More', 'Amina Lawal', 'Nassarawa GRA', 'Kano'],
        ['Kemi Kids & Couture', 'Kemi Fashola', 'Ajah', 'Lagos'],
        ['Divine Hands', 'Joy Akpan', 'Uyo', 'Akwa Ibom'],
        ['Ebube Atelier', 'Ebube Chukwu', 'Awka', 'Anambra'],
        ['Abuja Agbada Masters', 'Yakubu Danjuma', 'Maitama', 'FCT'],
        ['Ifeoma Iro & Buba', 'Ifeoma Nduka', 'New Haven', 'Enugu'],
        ['Tolu Tailored', 'Tolulope Ade', 'Ibadan', 'Oyo'],
        ['Delta Damask', 'Ese Omonigho', 'Asaba', 'Delta'],
        ['The Gele Bar', 'Yetunde Bakare', 'Victoria Island', 'Lagos'],
        ['Calabar Couture', 'Ekaette Bassey', 'Marian', 'Cross River'],
        ['Owerri Okpu-Agu', 'Obinna Iwu', 'Ikenegbu', 'Imo'],
        ['Zaria Zanna Wear', 'Zanna Musa', 'Samaru', 'Kaduna'],
        ['Ngozi Bridal Gowns', 'Ngozi Ume', 'GRA', 'Enugu'],
        ['Ife Royal Robes', 'Babatunde Oni', 'Ile-Ife', 'Osun'],
        ['Lagos Lace Lab', 'Chioma Okeke', 'Gbagada', 'Lagos'],
        ['Sokoto Sharp Cuts', 'Abdullahi Bello', 'Arkilla', 'Sokoto'],
        ['Bayelsa Bespoke', 'Preye Ebiware', 'Yenagoa', 'Bayelsa'],
        ['Benue Blue Studio', 'Doosuur Tersoo', 'Makurdi', 'Benue'],
        ['Folake Fabrics & Fits', 'Folake Ojo', 'Ota', 'Ogun'],
        ['Kano Kaftans', 'Sadiq Garba', 'Fagge', 'Kano'],
        ['Precious Petals Couture', 'Precious Ikem', 'Wuse', 'FCT'],
        ['Rivers Royal Tailors', 'Ibim Jack', 'D-Line', 'Rivers'],
        ['Stitch & Story', 'Adesuwa Osagie', 'Ugbowo', 'Edo'],
    ];

    public function run(RecalculateTailorRating $ratings): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('DemoFashionHouseSeeder only runs locally: it invents fifty tailors.');
        }

        if (User::query()->where('phone', 'like', self::PHONE_PREFIX.'%')->exists()) {
            $this->command?->warn('The demo tailors are already here (phones 0809 9...). Nothing done.');

            return;
        }

        $garment = GarmentType::query()->whereNull('retired_at')->orderBy('position')->first()
            ?? throw new RuntimeException('Seed the garment library first (php artisan db:seed).');

        mt_srand(20261001);

        $photos = $this->downloadPhotos();
        $customers = $this->customers(12);

        foreach (self::TAILORS as $i => [$shop, $owner, $area, $state]) {
            $tailor = User::create([
                'name' => $owner,
                'phone' => self::PHONE_PREFIX.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'password' => self::PIN,
                'role' => User::ROLE_TAILOR,
            ]);

            TailorProfile::create([
                'user_id' => $tailor->id,
                'business_name' => $shop,
                'slug' => TailorProfile::slugFor($shop),
                'bio' => "{$owner} runs {$shop} in {$area}, {$state}.",
                'location' => $area,
                'state' => $state,
            ]);

            // Listed: a paid month, so the directory's gate lets her through.
            Subscription::forTailor($tailor)->forceFill([
                'current_period_end' => now()->addDays(mt_rand(10, 300)),
                'grace_ends_at' => now()->addDays(320),
            ])->save();

            // Most have a cover; every eighth does not, to show the monogram.
            if ($photos !== [] && $i % 8 !== 5) {
                PortfolioItem::create([
                    'tailor_id' => $tailor->id,
                    'uploaded_by' => $tailor->id,
                    'path' => $photos[$i % count($photos)],
                    'caption' => "Work by {$shop}",
                ]);
            }

            // About a third free right now; the rest one to six on the table.
            $live = mt_rand(1, 100) <= 34 ? 0 : mt_rand(1, 6);

            for ($n = 0; $n < $live; $n++) {
                $order = Order::factory()->create([
                    'tailor_id' => $tailor->id,
                    'customer_id' => $customers[array_rand($customers)]->id,
                    'garment_type_id' => $garment->id,
                    'amount' => (string) (mt_rand(15, 120) * 1000),
                    'due_date' => now()->addDays(mt_rand(3, 30))->toDateString(),
                ]);
                $order->forceFill(['status' => mt_rand(1, 5) === 1 ? Order::READY : Order::IN_PROGRESS])->save();
            }

            // Most have been reviewed, mostly well; some are new.
            if (mt_rand(1, 100) <= 62) {
                foreach (range(1, mt_rand(1, 14)) as $r) {
                    $customer = $customers[array_rand($customers)];

                    $done = Order::factory()->create([
                        'tailor_id' => $tailor->id,
                        'customer_id' => $customer->id,
                        'garment_type_id' => $garment->id,
                        'amount' => (string) (mt_rand(15, 120) * 1000),
                    ]);
                    $done->forceFill(['status' => Order::COMPLETED, 'collected_at' => now()->subDays(mt_rand(5, 200))])->save();

                    Review::create([
                        'order_id' => $done->id,
                        'direction' => Review::CUSTOMER_TO_TAILOR,
                        'author_id' => $customer->id,
                        'subject_id' => $tailor->id,
                        'rating' => [5, 5, 5, 4, 4, 5, 3][mt_rand(0, 6)],
                    ])->publish();
                }
            }

            $ratings->handle($tailor);
        }

        $this->command?->info('50 demo tailors in the Fashion House (phones 0809 9...). PIN '.self::PIN.'.');
    }

    /** @return list<User> */
    private function customers(int $count): array
    {
        return array_map(fn (int $n) => User::create([
            'name' => "Demo Customer {$n}",
            'phone' => self::PHONE_PREFIX.'9'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'password' => self::PIN,
            'role' => User::ROLE_CUSTOMER,
        ]), range(1, $count));
    }

    /**
     * The landing page's photographs, fetched once into portfolio storage.
     *
     * @return list<string> stored paths
     */
    private function downloadPhotos(): array
    {
        $disk = Storage::disk('local');
        $paths = [];

        foreach (self::PHOTOS as $id) {
            $path = PortfolioItem::DIRECTORY."/demo-{$id}.jpg";

            if (! $disk->exists($path)) {
                $response = Http::timeout(30)->get(
                    "https://images.pexels.com/photos/{$id}/pexels-photo-{$id}.jpeg?auto=compress&cs=tinysrgb&w=800"
                );

                if (! $response->successful()) {
                    $this->command?->warn("Could not fetch photo {$id}; that tailor gets a monogram.");

                    continue;
                }

                $disk->put($path, $response->body());
            }

            $paths[] = $path;
        }

        return $paths;
    }
}
