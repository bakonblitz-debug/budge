<?php

use App\Models\MerchantAlias;
use App\Models\User;
use App\Services\MerchantNormalizer;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('returns the display name of a matching alias', function () {
    MerchantAlias::factory()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains', 'match_value' => 'METRO', 'display_name' => 'Metro',
    ]);

    expect(app(MerchantNormalizer::class)->normalize('METRO PLUS SOMEVILLE QC'))->toBe('Metro');
});

it('respects alias priority (highest wins)', function () {
    MerchantAlias::factory()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains', 'match_value' => 'PLUS', 'display_name' => 'Plus Store', 'priority' => 5,
    ]);
    MerchantAlias::factory()->create([
        'user_id' => $this->user->id,
        'match_type' => 'contains', 'match_value' => 'METRO', 'display_name' => 'Metro', 'priority' => 10,
    ]);

    expect(app(MerchantNormalizer::class)->normalize('METRO PLUS SOMEVILLE QC'))->toBe('Metro');
});

it('supports regex aliases', function () {
    MerchantAlias::factory()->create([
        'user_id' => $this->user->id,
        'match_type' => 'regex', 'match_value' => '/AMZN|AMAZON/', 'display_name' => 'Amazon',
    ]);

    expect(app(MerchantNormalizer::class)->normalize('AMZN MKTP CA*BS3M TORONTO ON'))->toBe('Amazon');
});

it('falls back to cleanup: strips trailing province and title-cases', function () {
    expect(app(MerchantNormalizer::class)->normalize('METRO PLUS SOMEVILLE QC'))
        ->toBe('Metro Plus Someville');
});

it('falls back to cleanup: strips a trailing store number', function () {
    expect(app(MerchantNormalizer::class)->normalize('CANADIAN TIRE #147'))
        ->toBe('Canadian Tire');
});

it('leaves mixed-case descriptions without a province or number intact', function () {
    expect(app(MerchantNormalizer::class)->normalize('Hydro Quebec'))->toBe('Hydro Quebec');
});

it('returns an empty string for an empty description', function () {
    expect(app(MerchantNormalizer::class)->normalize('   '))->toBe('');
});
