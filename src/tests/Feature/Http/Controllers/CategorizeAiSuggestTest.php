<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.classify', 'claude-haiku-4-5');
});

it('returns AI suggestions via an Inertia partial reload, without applying them', function () {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    $groceries = Category::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    Transaction::factory()->create([
        'user_id' => $user->id,
        'description' => 'LOBLAWS 99',
        'category_id' => null,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['classifications' => [
                [
                    'description' => 'LOBLAWS 99',
                    'category_id' => $groceries->id,
                    'confidence' => 0.9,
                    'suggested_rule' => ['match_type' => 'contains', 'match_value' => 'LOBLAWS'],
                ],
            ]])]],
        ], 200),
    ]);

    // Send the current asset version so the partial reload isn't rejected with
    // a 409 (Inertia's "version changed, do a full reload" response) once a
    // Vite build manifest exists.
    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->actingAs($user)->get('/categorize', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Categorize/Index',
        'X-Inertia-Partial-Data' => 'suggestions',
    ]);

    $response->assertOk();

    $item = $response->json('props.suggestions.items.0');
    expect($item['category_id'])->toBe($groceries->id)
        ->and($item['category_name'])->toBe('Groceries')
        ->and($item['match_value'])->toBe('LOBLAWS');

    // Suggestions are advisory only — nothing applied.
    expect(Transaction::query()->whereNull('category_id')->count())->toBe(1);
});

it('does not call the API on a normal page load', function () {
    $user = User::factory()->create(['onboarding_completed_at' => now()]);
    Category::factory()->create(['user_id' => $user->id]);
    Transaction::factory()->create(['user_id' => $user->id, 'category_id' => null]);

    Http::fake();

    $this->actingAs($user)->get('/categorize')->assertOk();

    // The Claude API must not be touched on a normal page load — suggestions
    // are an Inertia::optional prop, only computed on an explicit partial
    // reload. (Inertia's own SSR call may fire, so scope this to Anthropic.)
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic.com'));
});
