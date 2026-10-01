<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PlatformSetting;
use App\Models\TailorProfile;
use App\Services\Directory\TailorRanking;
use App\Support\WhatsApp;
use Illuminate\View\View;

/**
 * About us and Contact us.
 *
 * Controllers rather than Route::view because both say things that are only
 * true at request time: the About page counts the real house, through the
 * same gate as the directory, and the Contact page reads the help line from
 * Settings -- an admin changes that number when a SIM changes, and a page
 * that printed a dead line to somebody who cannot sign in would be the worst
 * place for it to be wrong.
 */
class PagesController extends Controller
{
    public function __construct(private readonly TailorRanking $ranking) {}

    public function about(): View
    {
        $listed = $this->ranking->listed();

        return view('public.about', [
            'house' => [
                'tailors' => (clone $listed)->count(),
                'states' => (clone $listed)->whereNotNull('state')->distinct()->count('state'),
                'in_the_making' => Order::query()
                    ->whereIn('status', TailorProfile::LIVE_ORDER_STATUSES)
                    ->whereIn('tailor_id', $this->ranking->listed()->select('user_id'))
                    ->count(),
                // Finished garments over the whole platform, not only listed
                // tailors: a lapsed subscription does not unmake a dress.
                'finished' => Order::query()->where('status', Order::COMPLETED)->count(),
            ],
        ]);
    }

    public function contact(): View
    {
        $phone = PlatformSetting::get(PlatformSetting::SUPPORT_PHONE) ?: null;

        return view('public.contact', [
            'phone' => $phone,
            'phoneSpaced' => $phone ? self::spaced($phone) : null,
            'whatsapp' => $phone
                ? WhatsApp::to($phone, 'Hello Rachels Closet, I need some help.')
                : null,
        ]);
    }

    /** "0815 207 0480": how it is read aloud, and easier to check against a card. */
    private static function spaced(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return strlen($digits) === 11
            ? substr($digits, 0, 4).' '.substr($digits, 4, 3).' '.substr($digits, 7)
            : $phone;
    }
}
