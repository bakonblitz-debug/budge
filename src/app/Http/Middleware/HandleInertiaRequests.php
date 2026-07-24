<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use App\Services\HmacService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => fn () => $request->user()
                    ? [
                        'id' => $request->user()->id,
                        'name' => $request->user()->name,
                        'email' => $request->user()->email,
                        'avatar_url' => $request->user()->avatar_url,
                        'has_completed_onboarding' => $request->user()->hasCompletedOnboarding(),
                    ]
                    : null,
            ],

            // Saved per-user appearance, applied on every page load.
            'theme' => fn () => $request->user() ? Setting::getValue('theme', 'light') : 'light',

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
                'type' => fn () => $request->session()->get('type', 'success'),
            ],

            // Per-session HMAC key for request signing (only if enabled)
            'hmac_key' => fn () => config('hmac.enabled')
                ? HmacService::deriveSessionKey()
                : null,
        ];
    }
}
