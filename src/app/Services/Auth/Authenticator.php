<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Single entry/exit point for establishing and tearing down a web session.
 *
 * Every way a user can become authenticated — password today, Google SSO and a
 * post-two-factor challenge in the future — funnels through {@see completeLogin()}
 * so session hardening (id regeneration) and the two-factor gate live in exactly
 * one place. A new sign-in method only has to verify identity, then hand the
 * authenticated request here.
 */
class Authenticator
{
    /**
     * Session key set on explicit logout. {@see \App\Http\Middleware\DevAutoLogin}
     * honours it so the dev auto-login does not silently sign a user back in.
     */
    public const LOGGED_OUT_FLAG = 'logged_out';

    /**
     * Verify password credentials and start the session guard. Identity only —
     * callers route the result through {@see completeLogin()} to finish.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    public function attemptPassword(array $credentials, bool $remember = false): bool
    {
        return Auth::guard('web')->attempt($credentials, $remember);
    }

    /**
     * Finalize an authenticated request, whatever proved the identity. Shared by
     * the password flow and (in future) the Google SSO callback once it has
     * resolved or provisioned the matching user and called Auth::login().
     */
    public function completeLogin(Request $request): RedirectResponse
    {
        $request->session()->regenerate();
        $request->session()->forget(self::LOGGED_OUT_FLAG);

        // Two-factor seam: when 2FA is wired up, a user with it enabled should be
        // held in a "pending" state here and redirected to the challenge screen
        // rather than straight to their destination.
        //
        //   if ($this->requiresTwoFactorChallenge($request->user())) {
        //       Auth::guard('web')->logout();              // not fully signed in yet
        //       $request->session()->put('auth.2fa.pending_id', $userId);
        //       return redirect()->route('two-factor.challenge');
        //   }

        return redirect()->intended('/');
    }

    /**
     * Tear down the session and flag it so dev auto-login stays out of the way.
     */
    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        // Written to the fresh post-invalidation session: this survives to the
        // next request, where DevAutoLogin checks it.
        $request->session()->put(self::LOGGED_OUT_FLAG, true);
    }

    /**
     * Whether the user must clear a second factor before {@see completeLogin()}
     * lets them through. Seam for the future: returns false until 2FA is wired
     * (a `two_factor_confirmed_at` column + challenge flow).
     */
    public function requiresTwoFactorChallenge(?User $user): bool
    {
        return $user?->hasEnabledTwoFactorAuthentication() ?? false;
    }
}
