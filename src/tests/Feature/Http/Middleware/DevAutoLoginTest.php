<?php

use App\Http\Middleware\DevAutoLogin;
use App\Models\User;
use App\Services\Auth\Authenticator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Runs the middleware with the environment forced to `local` (its only active
 * mode) and restores it in `finally` so the override never leaks to other tests.
 */
function runDevAutoLogin(callable $configureRequest = null): void
{
    $original = app()->environment();
    app()->detectEnvironment(fn () => 'local');

    try {
        $request = Request::create('/');
        $request->setLaravelSession(app('session')->driver());

        if ($configureRequest) {
            $configureRequest($request);
        }

        (new DevAutoLogin())->handle($request, fn () => response('ok'));
    } finally {
        app()->detectEnvironment(fn () => $original);
    }
}

afterEach(fn () => Auth::logout());

it('signs in the dev user in local when nothing is flagged', function () {
    $user = User::factory()->create();

    runDevAutoLogin();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id);
});

it('stays out of the way once the session is flagged logged out', function () {
    User::factory()->create();

    runDevAutoLogin(function (Request $request) {
        $request->session()->put(Authenticator::LOGGED_OUT_FLAG, true);
    });

    expect(Auth::check())->toBeFalse();
});
