<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Redirects authenticated users who haven't completed onboarding to the wizard.
 * Skipped for the onboarding routes themselves so the user can actually reach them.
 */
class RequireOnboarding
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user
            && ! $user->hasCompletedOnboarding()
            && ! $request->is('onboarding', 'onboarding/*')
        ) {
            return redirect('/onboarding');
        }

        return $next($request);
    }
}
