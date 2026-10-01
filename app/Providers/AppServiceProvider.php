<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * ONE allowance for every route that tests a spoken claim code.
         *
         * Six digits against a phone number is a million guesses, and this
         * limit plus the 48-hour expiry is all that stands in front of them.
         * Checking the code before she chooses a PIN is a second door onto the
         * same guess; a named limiter shares its counter across every route
         * that uses it, so adding that door did not double the rate.
         */
        RateLimiter::for('claim', fn (Request $request) => Limit::perMinute(8)->by($request->ip()));

        // The same, for a PIN reset code -- tighter, because that code opens
        // an account that already has orders, money and measurements in it.
        RateLimiter::for('reset', fn (Request $request) => Limit::perMinute(6)->by($request->ip()));
    }
}
