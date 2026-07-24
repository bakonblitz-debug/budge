<?php

use App\Models\Category;
use App\Models\User;
use Database\Seeders\CategoryKindSeeder;

it('backfills category kinds by name, case-insensitively, across all users', function () {
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    $groceries = Category::factory()->create(['user_id' => $u1->id, 'name' => 'Groceries', 'kind' => null]);
    $restaurants = Category::factory()->create(['user_id' => $u1->id, 'name' => 'Restaurants', 'kind' => null]);
    $investments = Category::factory()->create(['user_id' => $u1->id, 'name' => 'Investments', 'kind' => null]);
    $transfers = Category::factory()->create(['user_id' => $u1->id, 'name' => 'Transfers', 'kind' => null]);
    // A second user's lowercased name must also be backfilled (global, per-user-safe).
    $u2Groceries = Category::factory()->create(['user_id' => $u2->id, 'name' => 'groceries', 'kind' => null]);

    (new CategoryKindSeeder)->run();

    expect($groceries->fresh()->kind)->toBe('need')
        ->and($restaurants->fresh()->kind)->toBe('want')
        ->and($investments->fresh()->kind)->toBe('investment')
        ->and($transfers->fresh()->kind)->toBe('excluded')
        ->and($u2Groceries->fresh()->kind)->toBe('need');
});

it('leaves an unrecognised category null and never overwrites a user-set kind', function () {
    $user = User::factory()->create();

    $other = Category::factory()->create(['user_id' => $user->id, 'name' => 'Other', 'kind' => null]);
    // Already classified (deliberately "wrong") — the backfill must not touch it.
    $shopping = Category::factory()->create(['user_id' => $user->id, 'name' => 'Shopping', 'kind' => 'need']);

    (new CategoryKindSeeder)->run();

    expect($other->fresh()->kind)->toBeNull()
        ->and($shopping->fresh()->kind)->toBe('need'); // unchanged, not 'want'
});
