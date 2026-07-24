<?php

use App\Models\Category;
use App\Models\User;
use App\Services\Ai\TransactionClassifier;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.base_url', 'https://api.anthropic.com');
    config()->set('services.anthropic.version', '2023-06-01');
    config()->set('services.anthropic.models.classify', 'claude-haiku-4-5');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function fakeClassify(array $classifications): void
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

it('maps structured classifications back to categories', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    fakeClassify([[
        'description' => 'LOBLAWS 123',
        'category_id' => $groceries->id,
        'confidence' => 0.95,
        'suggested_rule' => ['match_type' => 'contains', 'match_value' => 'LOBLAWS'],
    ]]);

    $results = app(TransactionClassifier::class)->classify(['LOBLAWS 123']);

    expect($results)->toHaveCount(1)
        ->and($results[0]['category_id'])->toBe($groceries->id)
        ->and($results[0]['confidence'])->toBe(0.95)
        ->and($results[0]['suggested_rule']['match_value'])->toBe('LOBLAWS');
});

it('nulls out a hallucinated category id', function () {
    Category::factory()->create(['user_id' => $this->user->id]);

    fakeClassify([[
        'description' => 'MYSTERY',
        'category_id' => 999999,
        'confidence' => 0.9,
        'suggested_rule' => null,
    ]]);

    $results = app(TransactionClassifier::class)->classify(['MYSTERY']);

    expect($results[0]['category_id'])->toBeNull();
});

it('sends a prompt-cached system prefix and structured output format', function () {
    Category::factory()->create(['user_id' => $this->user->id]);
    fakeClassify([]);

    app(TransactionClassifier::class)->classify(['FOO']);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && ($body['system'][0]['cache_control']['type'] ?? null) === 'ephemeral'
            && ($body['output_config']['format']['type'] ?? null) === 'json_schema'
            && ($body['model'] ?? null) === 'claude-haiku-4-5';
    });
});

it('returns empty without calling the API when given no descriptions', function () {
    Http::fake();

    expect(app(TransactionClassifier::class)->classify([]))->toBe([]);

    Http::assertNothingSent();
});
