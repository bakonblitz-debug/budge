<?php

use App\Models\Asset;
use App\Models\Liability;
use App\Models\NetWorthSnapshot;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('renders the net worth page', function () {
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 10000]);

    $this->get('/net-worth')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('NetWorth/Index')
            ->where('current.assets', 10000)
            ->has('assets', 1)
        );
});

it('saves a snapshot via the endpoint', function () {
    Asset::factory()->create(['user_id' => $this->user->id, 'current_value' => 10000]);

    $this->post('/net-worth/snapshot')->assertRedirect();

    expect(NetWorthSnapshot::count())->toBe(1);
});

it('creates, updates and deletes an asset', function () {
    $this->post('/assets', ['name' => 'TFSA', 'type' => 'investment', 'current_value' => 25000])
        ->assertRedirect();
    $asset = Asset::first();
    expect($asset->name)->toBe('TFSA');

    $this->patch("/assets/{$asset->id}", ['name' => 'TFSA', 'type' => 'investment', 'current_value' => 26000])
        ->assertRedirect();
    expect((float) $asset->fresh()->current_value)->toBe(26000.0);

    $this->delete("/assets/{$asset->id}")->assertRedirect();
    expect(Asset::count())->toBe(0);
});

it('creates a liability', function () {
    $this->post('/liabilities', ['name' => 'Car loan', 'type' => 'loan', 'balance' => 15000, 'interest_rate' => 5.9])
        ->assertRedirect();

    expect(Liability::first()->name)->toBe('Car loan');
});

it('cannot touch another user\'s asset', function () {
    $other = User::factory()->create();
    $otherAsset = Asset::factory()->create(['user_id' => $other->id]);

    // BelongsToUser global scope hides it → route-model binding 404s.
    $this->delete("/assets/{$otherAsset->id}")->assertNotFound();
    expect(Asset::withoutGlobalScopes()->count())->toBe(1);
});
