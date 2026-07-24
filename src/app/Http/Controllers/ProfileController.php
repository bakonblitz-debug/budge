<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class ProfileController extends Controller
{
    /** Default notification preferences — toggles are stored only; delivery is wired later. */
    private const NOTIFICATION_DEFAULTS = [
        'budget_overspend' => true,
        'subscription_increase' => true,
        'low_balance_forecast' => false,
        'weekly_summary' => false,
    ];

    public function index()
    {
        return Inertia::render('Profile/Index', [
            'title' => 'Profile',
            'theme' => Setting::getValue('theme', 'light'),
            'notifications' => array_merge(
                self::NOTIFICATION_DEFAULTS,
                (array) Setting::getValue('notification_prefs', []),
            ),
        ]);
    }

    /**
     * Update account identity (name + email).
     */
    public function updateProfile(Request $request)
    {
        $user = auth()->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        $user->update($validated);

        return redirect()->back()->with(['message' => 'Profile updated.', 'type' => 'success']);
    }

    /**
     * Change the password for the logged-in user.
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        // The User 'password' cast hashes automatically on assignment.
        auth()->user()->update(['password' => $validated['password']]);

        return redirect()->back()->with(['message' => 'Password changed.', 'type' => 'success']);
    }

    /**
     * Upload (and replace) the user's avatar image.
     */
    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => ['required', 'image', 'max:2048', 'mimes:jpeg,png,webp'],
        ]);

        $user = auth()->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $path = $request->file('avatar')->store('avatars', 'public');
        $user->update(['avatar_path' => $path]);

        return redirect()->back()->with(['message' => 'Avatar updated.', 'type' => 'success']);
    }

    /**
     * Remove the user's avatar.
     */
    public function deleteAvatar()
    {
        $user = auth()->user();

        if ($user->avatar_path) {
            Storage::disk('public')->delete($user->avatar_path);
            $user->update(['avatar_path' => null]);
        }

        return redirect()->back()->with(['message' => 'Avatar removed.', 'type' => 'success']);
    }

    /**
     * Save appearance (theme) + notification preferences.
     */
    public function updatePreferences(Request $request)
    {
        $validated = $request->validate([
            'theme' => ['required', 'in:light,dark'],
            // Optional so the app-bar quick-toggle can persist theme alone
            // without resending the full notification set.
            'notifications' => ['sometimes', 'array'],
            'notifications.budget_overspend' => ['sometimes', 'boolean'],
            'notifications.subscription_increase' => ['sometimes', 'boolean'],
            'notifications.low_balance_forecast' => ['sometimes', 'boolean'],
            'notifications.weekly_summary' => ['sometimes', 'boolean'],
        ]);

        Setting::setValue('theme', $validated['theme']);

        if (array_key_exists('notifications', $validated)) {
            $current = array_merge(self::NOTIFICATION_DEFAULTS, (array) Setting::getValue('notification_prefs', []));
            Setting::setValue('notification_prefs', array_merge($current, $validated['notifications']), 'json');
        }

        return redirect()->back()->with(['message' => 'Preferences saved.', 'type' => 'success']);
    }
}
