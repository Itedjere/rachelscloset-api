<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Services\SendPushMessage;
use App\Support\NigerianStates;
use App\Support\UploadLimits;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;

/**
 * What the app needs to know about this server before it can do anything.
 *
 * Public, because all of it is read before sign-in and none of it is secret:
 * the upload ceiling comes from php.ini, and the VAPID public key is handed to
 * every browser that subscribes.
 *
 * One endpoint rather than several. Section 2 shipped this as `/push/config`;
 * folding it in here keeps the client to a single call it already has to make,
 * and stops "where does the app ask about the server" having two answers.
 */
class ConfigController extends Controller
{
    public function __construct(private readonly SendPushMessage $push) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => [
                /*
                 * Lets an upload form reject an oversized file instantly rather
                 * than spending a slow upload to be told no -- which on a
                 * metered connection is somebody's money.
                 */
                'max_upload_kb' => UploadLimits::maxKilobytes(),
                'max_upload_label' => UploadLimits::maxMegabytesLabel(),

                /*
                 * `enabled` false is how the app knows to hide the whole prompt
                 * rather than offer a button that cannot work.
                 */
                'push' => [
                    'enabled' => $this->push->configured(),
                    'public_key' => config('services.push.public_key'),
                ],

                /*
                 * Who a locked-out person rings. Public on purpose: the only
                 * person who needs it is somebody who cannot sign in. Null
                 * when an admin has not set one, and the page then says
                 * "contact Rachels Closet" rather than inventing a number.
                 */
                'support_phone' => $support = PlatformSetting::get(PlatformSetting::SUPPORT_PHONE) ?: null,
                // Built here, not in the app, so the 0803 -> 234803 rule lives
                // in one place (App\Support\WhatsApp).
                'support_whatsapp' => $support ? WhatsApp::to($support) : null,

                // The one list sign-up offers and the directory filters by.
                'states' => NigerianStates::ALL,
            ],
        ]);
    }
}
