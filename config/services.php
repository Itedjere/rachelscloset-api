<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * Web push. Absent these keys, SendPushMessage returns early and the whole
     * platform still works -- push is a courtesy on top of the in-app record,
     * never the thing a feature depends on. Generate them with `php artisan
     * push:vapid`.
     */
    'push' => [
        'subject' => env('VAPID_SUBJECT'),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * Flutterwave. v3 -- see FlutterwaveGateway for why that is deliberate.
     *
     * `sandbox` only takes effect locally: PaymentGatewayManager refuses to
     * hand back the fake gateway anywhere else, because its webhook handler
     * checks no signature.
     */
    'flutterwave' => [
        'secret' => env('FLUTTERWAVE_SECRET_KEY'),
        'public' => env('FLUTTERWAVE_PUBLIC_KEY'),
        // Compared verbatim against the verif-hash header; not an HMAC, so it
        // has to be long and unguessable.
        'webhook_hash' => env('FLUTTERWAVE_WEBHOOK_HASH'),
        'base_url' => env('FLUTTERWAVE_BASE_URL', 'https://api.flutterwave.com/v3'),
        'sandbox' => env('FLUTTERWAVE_SANDBOX', false),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
