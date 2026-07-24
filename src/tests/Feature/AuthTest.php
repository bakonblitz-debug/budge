<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('shows the login page to guests', function () {
    $this->get('/login')->assertOk();
});

it('redirects already-authenticated users away from the login page', function () {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);

    $this->actingAs($user)->get('/login')->assertRedirect('/');
});

it('logs a user in with valid credentials', function () {
    $user = User::factory()->create([
        'password' => Hash::make('correct-horse'),
        'onboarding_completed_at' => now(),
    ]);

    $response = $this->post('/login', [
        'email' => $user->email,
        'password' => 'correct-horse',
    ]);

    $response->assertRedirect('/');
    $this->assertAuthenticatedAs($user);
});

it('rejects invalid credentials and keeps the visitor a guest', function () {
    $user = User::factory()->create(['password' => Hash::make('correct-horse')]);

    $response = $this->from('/login')->post('/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect('/login');
    $response->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('logs the user out and flags the session so dev auto-login is suppressed', function () {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);

    $response = $this->actingAs($user)->post('/logout');

    $response->assertRedirect('/login');
    $this->assertGuest();
    expect(session('logged_out'))->toBeTrue();
});

it('redirects guests on protected routes to the login page', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/transactions')->assertRedirect('/login');
});
