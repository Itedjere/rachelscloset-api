<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops a suspended account mid-session.
 *
 * Refusing at login is not enough on its own: a token issued before the
 * suspension keeps working, so an admin suspending somebody changes nothing
 * until they happen to sign out. This runs on the whole authenticated group and
 * catches a token issued a moment before.
 *
 * It also lets a fixed-term suspension lapse on use rather than on a schedule.
 * Cron on shared hosting can quietly stop, and an account still locked out past
 * its own end date would be invisible until somebody complained.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            if ($user->suspensionHasExpired()) {
                $user->forceFill([
                    'status' => User::STATUS_ACTIVE,
                    'suspended_until' => null,
                ])->save();

                return $next($request);
            }

            // Bite now, not at the next sign-in.
            $request->user()->currentAccessToken()?->delete();

            abort(403, $user->suspended_until
                ? 'Your account is suspended until '.$user->suspended_until->format('j F Y').'.'
                : 'Your account is suspended.');
        }

        return $next($request);
    }
}
