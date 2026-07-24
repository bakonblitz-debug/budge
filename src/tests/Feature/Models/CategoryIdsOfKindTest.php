<?php

use App\Models\Category;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('resolves an income-kind category plus its untagged children', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id, 'name' => 'Income']);
    $child = Category::factory()->childOf($income)->create(['user_id' => $this->user->id, 'name' => 'Salary']); // kind left null
    $unrelated = Category::factory()->kind('need')->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    $ids = Category::idsOfKind('income');

    expect($ids)->toEqualCanonicalizing([$income->id, $child->id])
        ->and($ids)->not->toContain($unrelated->id);
});

it('resolves an excluded-kind tree (duplicate-safe: multiple same-kind trees all resolve)', function () {
    $excludedA = Category::factory()->kind('excluded')->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    $childA = Category::factory()->childOf($excludedA)->create(['user_id' => $this->user->id, 'name' => 'Card Payment']);
    $excludedB = Category::factory()->kind('excluded')->create(['user_id' => $this->user->id, 'name' => 'Internal Moves']);

    $ids = Category::idsOfKind('excluded');

    expect($ids)->toEqualCanonicalizing([$excludedA->id, $childA->id, $excludedB->id]);
});

it('returns an empty array when no category matches the kind', function () {
    Category::factory()->kind('need')->create(['user_id' => $this->user->id]);

    expect(Category::idsOfKind('investment'))->toBe([]);
});

it('returns plain ints, not numeric strings', function () {
    $income = Category::factory()->kind('income')->create(['user_id' => $this->user->id]);

    $ids = Category::idsOfKind('income');

    expect($ids[0])->toBeInt()->toBe($income->id);
});
