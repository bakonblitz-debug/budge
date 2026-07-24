<?php

use App\Models\Budget;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\EnvelopePool;
use App\Models\FixedExpense;
use App\Models\IncomeEntry;
use App\Models\SavingsMilestone;
use App\Models\UserProfile;
use App\Models\YearlySnapshot;

it('builds each newly-factoried model', function (string $model) {
    $instance = $model::factory()->create();

    expect($instance->exists)->toBeTrue()
        ->and($instance->user_id)->not->toBeNull();
})->with([
    Budget::class,
    CategoryRule::class,
    EnvelopePool::class,
    FixedExpense::class,
    IncomeEntry::class,
    SavingsMilestone::class,
    UserProfile::class,
    YearlySnapshot::class,
]);

it('supports the forCategory state on category-scoped factories', function () {
    $category = Category::factory()->create();

    $rule = CategoryRule::factory()->forCategory($category)->create();
    $budget = Budget::factory()->forCategory($category)->create();

    expect($rule->category_id)->toBe($category->id)
        ->and($rule->user_id)->toBe($category->user_id)
        ->and($budget->category_id)->toBe($category->id);
});

it('marks a savings milestone reached', function () {
    $milestone = SavingsMilestone::factory()->reached()->target(5000)->create();

    expect($milestone->is_reached)->toBeTrue()
        ->and($milestone->reached_at)->not->toBeNull()
        ->and((float) $milestone->target_amount)->toBe(5000.00);
});
