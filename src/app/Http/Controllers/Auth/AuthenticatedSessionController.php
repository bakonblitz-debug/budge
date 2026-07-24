<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\Authenticator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Email + password sign-in. One of several planned session "starters" — Google
 * SSO ({@see \App\Http\Controllers\Auth\OAuthController}, future) is a sibling —
 * all of which finish through {@see Authenticator::completeLogin()}.
 */
class AuthenticatedSessionController extends Controller
{
    public function __construct(private readonly Authenticator $authenticator) {}

    /**
     * Show the login screen. Already-authenticated visitors have no business
     * here, so bounce them home.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect('/');
        }

        return Inertia::render('Auth/Login', [
            'title' => 'Sign in',
            // Flip on once the OAuth routes + Socialite are wired; the page
            // renders a "Continue with Google" button when true.
            'googleSsoEnabled' => false,
        ]);
    }

    /**
     * Verify credentials and start the session.
     */
    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! $this->authenticator->attemptPassword($credentials, $request->boolean('remember'))) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        return $this->authenticator->completeLogin($request);
    }

    /**
     * Log the user out and return them to the login screen.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $this->authenticator->logout($request);

        return redirect('/login');
    }
}
