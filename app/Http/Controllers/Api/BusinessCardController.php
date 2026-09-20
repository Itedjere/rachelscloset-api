<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Qr\QrCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything a tailor's business card needs.
 *
 * The card itself is drawn in the browser, on a canvas, so that "save it as a
 * picture" needs no HTML-to-canvas library and no server-side rasteriser --
 * and so the webfonts the rest of the brand uses actually apply, which they
 * would not if an SVG were rasterised through an <img>.
 *
 * That leaves the server with one job: hand over the words and the grid.
 */
class BusinessCardController extends Controller
{
    public function __construct(private readonly QrCode $qr) {}

    public function show(Request $request): JsonResponse
    {
        $tailor = $request->user();

        abort_unless($tailor->isTailor(), 404);

        $profile = $tailor->tailorProfile;

        abort_unless($profile, 422, 'Your Fashion House page is not set up yet.');

        /*
         * The address on the card. Short on purpose -- every character is a
         * module, and a denser code is smaller per module at card size.
         *
         * It is also the one thing here that must never change: this is
         * printed on cardboard that cannot be reissued. TailorProfile
         * generates the slug once and never moves it on a rename.
         */
        $url = route('tailor', $profile->slug);

        return response()->json(['data' => [
            'business_name' => $profile->business_name,
            'name' => $tailor->name,
            'location' => collect([$profile->location, $profile->state])->filter()->join(', '),
            'whatsapp' => $profile->whatsapp_phone,
            'url' => $url,
            // Without the scheme: shorter to read, and nobody types it in.
            'url_label' => preg_replace('#^https?://#', '', $url),
            'slug' => $profile->slug,

            // Level M, because this is going on paper. See QrCode::PRINT.
            'qr' => $this->qr->matrix($url, QrCode::PRINT),
        ]]);
    }
}
