<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Support\RetryAfter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        /*
         * There is no `login` route, and there is not going to be: sign-in is
         * a React page on another origin. Laravel's default for an
         * unauthenticated guest is route('login'), which throws before the
         * JSON handler below ever runs -- so any request to an API route
         * without a bearer token and without an Accept: application/json
         * header came back as a 500 instead of a 401.
         *
         * Not hypothetical. GET /api/files/{path} returns a file, so it is
         * exactly the kind of URL a browser fetches directly.
         *
         * Returning null means no redirect, which leaves the exception
         * handler to answer 401 the way it already wanted to.
         */
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * "Too many attempts." is where Laravel stops, and it leaves somebody
         * with no idea whether to try again in ten seconds or tomorrow — so
         * they sit refreshing, or decide the site is broken. That matters more
         * here than usual: a six-digit PIN means people mistype, and the
         * person who just locked themselves out is the one least able to work
         * out what to do about it. The wait is already known; this passes it
         * on, in the body as well as the header, because a browser cannot read
         * Retry-After across origins unless it is exposed.
         */
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if (! ($request->is('api/*') || $request->expectsJson())) {
                return null;
            }

            $seconds = (int) ($e->getHeaders()['Retry-After'] ?? 60);

            return response()->json([
                'message' => 'Too many attempts. Try again in '.RetryAfter::describe($seconds).'.',
                'retry_after' => $seconds,
            ], 429, $e->getHeaders());
        });
    })->create();
