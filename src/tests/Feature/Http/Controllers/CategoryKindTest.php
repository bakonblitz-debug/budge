<?php

use App\Models\Category;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('stores a category with a kind', function () {
    $this->post('/categories', [
        'name' => 'Dining Out',
        'kind' => 'want',
    ])->assertSessionHasNoErrors();

    expect(Category::where('name', 'Dining Out')->first()->kind)->toBe('want');
});

it('stores a category with a null kind (unclassified)', function () {
    $this->post('/categories', [
        'name' => 'Misc',
    ])->assertSessionHasNoErrors();

    expect(Category::where('name', 'Misc')->first()->kind)->toBeNull();
});

it('rejects an invalid kind', function () {
    $this->post('/categories', [
        'name' => 'Bad',
        'kind' => 'luxury',
    ])->assertSessionHasErrors('kind');

    expect(Category::where('name', 'Bad')->exists())->toBeFalse();
});

it('updates an existing category kind', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries', 'kind' => null]);

    $this->patch("/categories/{$category->id}", [
        'name' => 'Groceries',
        'kind' => 'need',
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->kind)->toBe('need');
});

it('clears a kind back to unclassified', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping', 'kind' => 'want']);

    $this->patch("/categories/{$category->id}", [
        'name' => 'Shopping',
        'kind' => null,
    ])->assertSessionHasNoErrors();

    expect($category->fresh()->kind)->toBeNull();
});

it('exposes the allowed kinds as a model constant', function () {
    expect(Category::KINDS)->toBe(['need', 'want', 'saving', 'investment', 'excluded', 'income']);
});
