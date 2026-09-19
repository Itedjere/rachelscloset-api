<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SendPushMessage;
use App\Support\UploadLimits;
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
            ],
        ]);
    }
}
