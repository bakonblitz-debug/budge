<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\Authenticator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Convenience for local dev: signs in the seeded dev user so the app is usable
 * without typing credentials every reload, keeping the BelongsToUser global
 * scope satisfied.
 *
 * It now yields to the real login flow — once a user has explicitly logged out
 * (the session carries {@see Authenticator::LOGGED_OUT_FLAG}) it stays out of
 * the way, so logout sticks and the login screen can do its job.
 */
class DevAutoLogin
{
    public function handle(Request $request, Closure $next)
    {
        if (app()->environment('local')
            && ! Auth::check()
            && ! $request->session()->get(Authenticator::LOGGED_OUT_FLAG)
        ) {
            $user = User::query()->orderBy('id')->first();

            if ($user) {
                Auth::login($user);
            }
        }

        return $next($request);
    }
}
