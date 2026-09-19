<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PushSubscriptionController;
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
| Public: what the browser needs before it can even offer to turn alerts on.
| Read before sign-in so the prompt is not offered on an environment that has
| no VAPID keys and could never deliver.
*/
Route::get('/push/config', [PushSubscriptionController::class, 'config']);

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

    Route::get('/push/subscriptions', [PushSubscriptionController::class, 'index']);
    Route::post('/push/subscriptions', [PushSubscriptionController::class, 'store']);
    Route::delete('/push/subscriptions', [PushSubscriptionController::class, 'destroy']);
});
