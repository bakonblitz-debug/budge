<?php

use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create([
        'onboarding_completed_at' => now(),
        'password' => Hash::make('current-pass-1'),
    ]);
    $this->actingAs($this->user);
});

it('renders the profile page with theme and notification props', function () {
    $this->get('/profile')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Profile/Index')
            ->where('theme', 'light')
            ->has('notifications.budget_overspend'));
});

it('updates name and email', function () {
    $this->post('/profile', ['name' => 'New Name', 'email' => 'new@example.com'])
        ->assertRedirect();

    $fresh = $this->user->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->email)->toBe('new@example.com');
});

it('rejects an email already taken by another user', function () {
    User::factory()->create(['email' => 'taken@example.com']);

    $this->post('/profile', ['name' => 'X', 'email' => 'taken@example.com'])
        ->assertSessionHasErrors('email');
});

it('changes the password when the current password is correct', function () {
    $this->post('/profile/password', [
        'current_password' => 'current-pass-1',
        'password' => 'brand-new-pass-2',
        'password_confirmation' => 'brand-new-pass-2',
    ])->assertRedirect();

    expect(Hash::check('brand-new-pass-2', $this->user->fresh()->password))->toBeTrue();
});

it('rejects a password change with the wrong current password', function () {
    $this->post('/profile/password', [
        'current_password' => 'wrong',
        'password' => 'brand-new-pass-2',
        'password_confirmation' => 'brand-new-pass-2',
    ])->assertSessionHasErrors('current_password');

    expect(Hash::check('current-pass-1', $this->user->fresh()->password))->toBeTrue();
});

it('rejects a password change when confirmation does not match', function () {
    $this->post('/profile/password', [
        'current_password' => 'current-pass-1',
        'password' => 'brand-new-pass-2',
        'password_confirmation' => 'different',
    ])->assertSessionHasErrors('password');
});

it('uploads an avatar, storing it on the public disk', function () {
    Storage::fake('public');

    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png', 100, 100)])
        ->assertRedirect();

    $path = $this->user->fresh()->avatar_path;
    expect($path)->not->toBeNull();
    Storage::disk('public')->assertExists($path);
});

it('replaces the previous avatar on re-upload', function () {
    Storage::fake('public');

    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('one.png')]);
    $first = $this->user->fresh()->avatar_path;

    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('two.png')]);
    $second = $this->user->fresh()->avatar_path;

    expect($second)->not->toBe($first);
    Storage::disk('public')->assertMissing($first);
    Storage::disk('public')->assertExists($second);
});

it('rejects a non-image avatar', function () {
    Storage::fake('public');

    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->create('virus.pdf', 50, 'application/pdf')])
        ->assertSessionHasErrors('avatar');
});

it('removes the avatar', function () {
    Storage::fake('public');
    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png')]);
    $path = $this->user->fresh()->avatar_path;

    $this->delete('/profile/avatar')->assertRedirect();

    expect($this->user->fresh()->avatar_path)->toBeNull();
    Storage::disk('public')->assertMissing($path);
});

it('saves theme and notification preferences', function () {
    $this->post('/profile/preferences', [
        'theme' => 'dark',
        'notifications' => [
            'budget_overspend' => false,
            'subscription_increase' => true,
            'low_balance_forecast' => true,
            'weekly_summary' => false,
        ],
    ])->assertRedirect();

    expect(Setting::getValue('theme'))->toBe('dark');
    $prefs = Setting::getValue('notification_prefs');
    expect($prefs['budget_overspend'])->toBeFalse()
        ->and($prefs['low_balance_forecast'])->toBeTrue();
});

it('rejects an invalid theme', function () {
    $this->post('/profile/preferences', ['theme' => 'neon', 'notifications' => []])
        ->assertSessionHasErrors('theme');
});

it('shares avatar_url and theme with every page', function () {
    Storage::fake('public');
    $this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png')]);
    Setting::setValue('theme', 'dark');

    $this->get('/')
        ->assertInertia(fn ($page) => $page
            ->where('theme', 'dark')
            ->where('auth.user.avatar_url', fn ($v) => is_string($v) && str_contains($v, 'avatars/')));
});
