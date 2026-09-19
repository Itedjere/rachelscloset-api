<?php

use App\Http\Controllers\Api\Admin\GarmentTypeController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductionStepController;
use App\Http\Controllers\Api\Admin\RefundController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\ConfigController;
use App\Http\Controllers\Api\CustomerLookupController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\OrderStepController;
use App\Http\Controllers\Api\OrderStepPhotoController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushSubscriptionController;
use App\Http\Controllers\Api\StepTemplateController;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API
|--------------------------------------------------------------------------
|
| Sanctum in bearer-token mode, not SPA cookie sessions: the React app is a
| separately deployed static bundle on another origin, so there is no shared
| session to ride on and no CSRF dance to perform.
|
| Throttles are tighter on the way in than the framework default because the
| username here is a phone number, which is guessable, and the secret is six
| digits. Rate limiting is doing real work on this platform, not ceremony.
|
*/

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');

/*
| Public: what the app needs to know about this server. The upload ceiling
| comes from php.ini and the VAPID public key is handed to every browser that
| subscribes, so none of it is secret and all of it is read before sign-in.
*/
Route::get('/config', [ConfigController::class, 'show']);

/*
| Where Flutterwave reports a payment.
|
| Unauthenticated because the provider has no session -- the verif-hash header
| is the authentication, checked in FlutterwaveGateway. Throttled generously:
| a provider legitimately retries, but this endpoint should not be a free way
| to fill the webhook_events table.
*/
Route::post('/webhooks/flutterwave', PaymentWebhookController::class)
    ->middleware('throttle:120,1')
    ->name('webhooks.flutterwave');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    /*
    | Notifications.
    |
    | `unread-count` is its own endpoint because the header polls it and the
    | full list is paginated with a JSON payload per row -- the badge should not
    | drag twenty of those across a metered connection every thirty seconds.
    |
    | The literal paths are declared before `{notification}` so that
    | /notifications/preferences is not read as a notification with the id
    | "preferences".
    */
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::get('/notifications/preferences', [NotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [NotificationController::class, 'updatePreferences']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    /*
    | Your own account.
    |
    | No phone number here -- it is the username, and moving it needs the claim
    | flow from Section 11 to prove the new one is really hers.
    */
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar']);
    Route::delete('/profile/avatar', [ProfileController::class, 'deleteAvatar']);

    /*
    | Uploaded files are streamed through a controller rather than a public
    | symlink: symlinks are unreliable on shared hosting, and routing every file
    | through one place is what makes access control possible at all. FileAccess
    | resolves each path back to the record that owns it.
    */
    Route::get('/files/{path}', [FileController::class, 'show'])
        ->where('path', '.*')
        ->name('files.show');

    /*
    | Finding the customer in front of you, by exact phone number only.
    | Throttled: it answers "is this number on the platform", and that should
    | not be something anybody can sweep.
    */
    Route::get('/customers/lookup', CustomerLookupController::class)
        ->middleware('throttle:30,1');

    /*
    | Orders.
    |
    | Every route is scoped to the person asking inside the controller; there
    | is no parameter anywhere for whose orders to act on.
    */
    Route::get('/orders', [OrderController::class, 'index']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/ready', [OrderController::class, 'markReady']);
    Route::post('/orders/{order}/collected', [OrderController::class, 'markCollected']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    /*
    | The tracker. One tap by the tailor, one notification to the customer.
    | Both sides read the list; only the tailor writes it.
    */
    Route::get('/orders/{order}/steps', [OrderStepController::class, 'index']);
    Route::put('/orders/{order}/steps/{step}', [OrderStepController::class, 'update']);

    /*
    | And the proof. Uploading is the tailor's; both sides see the result,
    | because being shown the work is the point of taking it.
    */
    Route::post('/orders/{order}/steps/{step}/photos', [OrderStepPhotoController::class, 'store']);
    Route::delete('/orders/{order}/steps/{step}/photos/{photo}', [OrderStepPhotoController::class, 'destroy']);

    Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm']);
    Route::post('/orders/{order}/release', [OrderController::class, 'release']);

    /*
    | Where a tailor is paid. Resolving is separate from saving so she sees
    | the name the bank returned before anything is stored.
    */
    Route::get('/banks', [BankAccountController::class, 'banks']);
    Route::get('/profile/bank', [BankAccountController::class, 'show']);
    Route::post('/profile/bank/resolve', [BankAccountController::class, 'resolve'])
        ->middleware('throttle:20,1');
    Route::post('/profile/bank', [BankAccountController::class, 'store'])
        ->middleware('throttle:20,1');

    Route::post('/orders/{order}/pay', [PaymentController::class, 'initialise']);
    Route::post('/payments/confirm', [PaymentController::class, 'confirm']);

    /*
    | The step library and arrangements.
    |
    | Reading is open to any signed-in account: a customer watching her order
    | needs the same labels and the same recordings the tailor works from.
    | Writing the library is admin-only; writing an arrangement is scoped to
    | whoever is asking, inside StepTemplateController.
    */
    Route::get('/garment-types', [GarmentTypeController::class, 'index']);
    Route::get('/steps', [StepTemplateController::class, 'library']);
    Route::get('/garment-types/{garmentType}/steps', [StepTemplateController::class, 'show']);
    Route::put('/garment-types/{garmentType}/steps', [StepTemplateController::class, 'update']);
    Route::delete('/garment-types/{garmentType}/steps', [StepTemplateController::class, 'destroy']);

    Route::prefix('admin')->middleware('role:'.User::ROLE_ADMIN)->group(function (): void {
        /*
        | `reorder` is declared before `{garmentType}` so it is not read as a
        | garment type with the id "reorder".
        */
        Route::post('/garment-types', [GarmentTypeController::class, 'store']);
        Route::put('/garment-types/reorder', [GarmentTypeController::class, 'reorder']);
        Route::put('/garment-types/{garmentType}', [GarmentTypeController::class, 'update']);
        Route::post('/garment-types/{garmentType}/retire', [GarmentTypeController::class, 'retire']);

        /*
        | Every order, not just the ones an admin is on -- a refund cannot be
        | decided without seeing what was paid. Declared before the refund
        | route so the more specific path is not shadowed.
        */
        Route::get('/orders', [AdminOrderController::class, 'index']);
        Route::get('/orders/{order}', [AdminOrderController::class, 'show']);

        // Refunds are a judgement about work already done, so an admin makes
        // them. Partial is the common case.
        Route::post('/orders/{order}/refund', RefundController::class);

        Route::get('/steps', [ProductionStepController::class, 'index']);
        Route::post('/steps', [ProductionStepController::class, 'store']);
        Route::put('/steps/{productionStep}', [ProductionStepController::class, 'update']);
        Route::post('/steps/{productionStep}/voice-note', [ProductionStepController::class, 'voiceNote']);
        Route::post('/steps/{productionStep}/retire', [ProductionStepController::class, 'retire']);
    });

    Route::get('/push/subscriptions', [PushSubscriptionController::class, 'index']);
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy']);
});
