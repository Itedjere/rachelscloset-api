<?php

namespace App\Providers;

use App\Models\PlatformSetting;
use App\Support\WhatsApp;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
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

        /*
         * Where the app lives, for every public page. The public site is
         * Blade on this server and the app is React on another origin, so a
         * "Sign in" or "Join" link has to be absolute -- and before this, none
         * existed: the public site had no way into the app at all.
         */
        View::composer('public.layout', function ($view) {
            /*
             * The help line, for the footer's Contact column -- the same
             * setting the "Forgot your PIN?" page reads, so changing it in
             * admin Settings changes it everywhere at once. Null when unset,
             * and the footer then simply omits it.
             */
            $support = PlatformSetting::get(PlatformSetting::SUPPORT_PHONE) ?: null;

            $view->with([
                'supportPhone' => $support,
                'supportWhatsapp' => $support ? WhatsApp::to($support) : null,
            ]);
        });

        View::composer('public.*', fn ($view) => $view->with(
            'appUrl',
            rtrim((string) config('app.frontend_url'), '/'),
        ));
    }
}
