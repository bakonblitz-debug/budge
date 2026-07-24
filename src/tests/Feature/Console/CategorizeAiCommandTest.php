<?php

use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.classify', 'claude-haiku-4-5');
});

function fakeClassifyResponse(array $classifications): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['classifications' => $classifications]),
            ]],
        ], 200),
    ]);
}

it('applies high-confidence categorizations and learns a rule', function () {
    $user = User::factory()->create();
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    Transaction::factory()->count(2)->create([
        'user_id' => $user->id,
        'description' => 'LOBLAWS 123',
        'category_id' => null,
    ]);

    fakeClassifyResponse([[
        'description' => 'LOBLAWS 123',
        'category_id' => $groceries->id,
        'confidence' => 0.95,
        'suggested_rule' => ['match_type' => 'contains', 'match_value' => 'LOBLAWS'],
    ]]);

    $this->artisan('budget:categorize-ai', ['--user' => $user->id, '--apply' => true])
        ->assertSuccessful();

    $this->actingAs($user);
    expect(Transaction::whereNull('category_id')->count())->toBe(0)
        ->and(Transaction::where('category_id', $groceries->id)->count())->toBe(2)
        ->and(CategoryRule::where('category_id', $groceries->id)->where('match_value', 'LOBLAWS')->exists())->toBeTrue();
});

it('does not apply below the confidence threshold', function () {
    $user = User::factory()->create();
    $cat = Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'description' => 'MAYBE STORE', 'category_id' => null]);

    fakeClassifyResponse([[
        'description' => 'MAYBE STORE',
        'category_id' => $cat->id,
        'confidence' => 0.4,
        'suggested_rule' => null,
    ]]);

    $this->artisan('budget:categorize-ai', ['--user' => $user->id, '--apply' => true, '--threshold' => 0.8])
        ->assertSuccessful();

    $this->actingAs($user);
    expect(Transaction::whereNull('category_id')->count())->toBe(1);
});
