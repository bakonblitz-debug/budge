<?php

use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('creates an alias and re-applies it to existing transactions', function () {
    $txn = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'METRO PLUS SOMEVILLE QC',
        'merchant_name' => 'Metro Plus Someville', // prior cleanup value
        'hash' => bin2hex(random_bytes(16)),
    ]);

    $this->post('/merchants', [
        'match_type' => 'contains',
        'match_value' => 'METRO',
        'display_name' => 'Metro',
        'priority' => 10,
    ])->assertRedirect();

    expect(MerchantAlias::count())->toBe(1)
        ->and($txn->fresh()->merchant_name)->toBe('Metro');
});

it('re-applies cleanup when an alias is removed', function () {
    $alias = MerchantAlias::factory()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains', 'match_value' => 'METRO', 'display_name' => 'Metro',
    ]);
    $txn = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'description' => 'METRO PLUS SOMEVILLE QC',
        'merchant_name' => 'Metro',
        'hash' => bin2hex(random_bytes(16)),
    ]);

    $this->delete("/merchants/{$alias->id}")->assertRedirect();

    expect(MerchantAlias::count())->toBe(0)
        ->and($txn->fresh()->merchant_name)->toBe('Metro Plus Someville');
});

it('validates the match type', function () {
    $this->post('/merchants', [
        'match_type' => 'bogus',
        'match_value' => 'X',
        'display_name' => 'Y',
    ])->assertSessionHasErrors('match_type');
});
