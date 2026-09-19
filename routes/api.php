<?php

use App\Http\Controllers\Api\AuthController;
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

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
