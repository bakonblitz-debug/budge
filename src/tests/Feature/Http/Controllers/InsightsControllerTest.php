<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

function seedInsightsData(User $user): void
{
    $restaurants = Category::factory()->create(['user_id' => $user->id, 'name' => 'Restaurants']);
    Budget::factory()->forCategory($restaurants)->create(['amount' => 100]);

    $date = Carbon::now()->startOfMonth()->addDays(2)->format('Y-m-d H:i:s');
    Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $restaurants->id,
        'transaction_date' => $date,
        'description' => 'PIZZA PALACE',
        'amount' => -250.00,
        'hash' => 'ins1',
        'is_excluded' => false,
    ]);
}

// ── Task 8: /insights route ──────────────────────────────────────────────────

it('GET /insights renders Insights/Index with required insight keys', function () {
    $this->get('/insights')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Insights/Index')
            ->has('insights.saving_health')
            ->has('insights.trend')
            ->has('insights.windfalls')
        );
});

it('GET /insights also carries budgetRule prop', function () {
    $this->get('/insights')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Insights/Index')
            ->has('budgetRule')
        );
});

it('GET /statistics redirects to /insights', function () {
    $this->get('/statistics')->assertRedirect('/insights');
});

it('query count for /insights stays within the ceiling', function () {
    DB::enableQueryLog();

    $this->get('/insights')->assertOk();

    $count = count(DB::getQueryLog());
    // Ceiling: measured at ~63 queries on a fresh SQLite DB; allow headroom to 70.
    // Sources: auth (1), trend (3), windfalls (3), savingHealth/projection (~15),
    //   netWorth (6), goalProgress (1), budgetRule (~18), cutting/SpendingCutAnalyzer (~16).
    //   Memoized: income ids, netWorth current, and monthlyTrend share results across methods.
    // Bumped to 78 (SUB-5 fix 1): buildMonthlyTrend now also excludes
    // investment/saving-kind categories from spending (2 × idsOfKind, 2
    // queries each = +4) so it stops overcounting contributions as spend.
    expect($count)->toBeLessThanOrEqual(78);
});

it('does not call the AI on a normal /insights page load', function () {
    config()->set('services.anthropic.key', 'test-key');
    seedInsightsData($this->user);

    Http::fake();

    $this->get('/insights')->assertOk();

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic.com'));
});

it('returns an optional AI summary on a partial reload when configured', function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.analyze', 'claude-sonnet-4-6');
    seedInsightsData($this->user);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Restaurants are your biggest cuttable category.']],
        ], 200),
    ]);

    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->get('/insights', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Insights/Index',
        'X-Inertia-Partial-Data' => 'aiSummary',
    ]);

    $response->assertOk();
    expect($response->json('props.aiSummary'))->toBe('Restaurants are your biggest cuttable category.');
});

it('returns a null AI summary when the API key is not configured', function () {
    config()->set('services.anthropic.key', '');
    seedInsightsData($this->user);

    Http::fake();

    $version = app(HandleInertiaRequests::class)->version(request());

    $response = $this->get('/insights', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) $version,
        'X-Inertia-Partial-Component' => 'Insights/Index',
        'X-Inertia-Partial-Data' => 'aiSummary',
    ]);

    $response->assertOk();
    expect($response->json('props.aiSummary'))->toBeNull();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'anthropic.com'));
});
