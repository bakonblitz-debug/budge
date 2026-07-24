<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\SpendingAnalyzer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.analyze', 'claude-sonnet-4-6');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('aggregates expenses by month, category, and merchant', function () {
    $groceries = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);

    $thisMonth = Carbon::now()->startOfMonth()->addDays(2)->format('Y-m-d H:i:s');
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $groceries->id,
        'transaction_date' => $thisMonth, 'description' => 'LOBLAWS', 'amount' => -50.00,
        'hash' => 'h1', 'is_excluded' => false,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $groceries->id,
        'transaction_date' => $thisMonth, 'description' => 'LOBLAWS', 'amount' => -30.00,
        'hash' => 'h2', 'is_excluded' => false,
    ]);
    // income is ignored
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $groceries->id,
        'transaction_date' => $thisMonth, 'description' => 'PAYCHECK', 'amount' => 2200.00,
        'hash' => 'h3', 'is_excluded' => false,
    ]);

    $agg = app(SpendingAnalyzer::class)->aggregate(3);

    expect($agg['category_totals']['Groceries'])->toBe(80.0)
        ->and($agg['top_merchants'][0]['description'])->toBe('LOBLAWS')
        ->and($agg['top_merchants'][0]['total'])->toBe(80.0)
        ->and($agg['top_merchants'][0]['count'])->toBe(2);
});

it('aggregates using an overflow-safe window from a day-31 anchor', function () {
    Carbon::setTestNow('2026-05-31 12:00:00');
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    // A plain subMonths(3) from a May-31 anchor overflows into March, silently
    // dropping February from the lookback. subMonthsNoOverflow keeps it.
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $cat->id,
        'transaction_date' => '2026-02-15 10:00:00', 'description' => 'FEB', 'amount' => -40,
        'hash' => 'sa-w31-feb', 'is_excluded' => false,
    ]);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $cat->id,
        'transaction_date' => '2026-05-31 10:00:00', 'description' => 'MAY', 'amount' => -60,
        'hash' => 'sa-w31-may', 'is_excluded' => false,
    ]);

    $agg = app(SpendingAnalyzer::class)->aggregate(3);

    expect($agg['category_totals']['Shopping'])->toBe(100.0)
        ->and($agg['category_by_month'])->toHaveKey('2026-02');

    Carbon::setTestNow(null);
});

it('returns a structured narrative from the model', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Dining']);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $cat->id,
        'transaction_date' => Carbon::now()->format('Y-m-d H:i:s'), 'description' => 'RESTO', 'amount' => -120.00,
        'hash' => 'd1', 'is_excluded' => false,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode([
                'summary' => 'Dining is your largest category.',
                'observations' => [
                    ['title' => 'Dining spend', 'detail' => '$120 on dining.', 'severity' => 'medium'],
                ],
            ])]],
        ], 200),
    ]);

    $result = app(SpendingAnalyzer::class)->analyze(3);

    expect($result['summary'])->toBe('Dining is your largest category.')
        ->and($result['observations'])->toHaveCount(1)
        ->and($result['observations'][0]['severity'])->toBe('medium');
});

it('sends merchants as a hidden signal but instructs category-only output', function () {
    $cat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Groceries']);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $cat->id,
        'transaction_date' => Carbon::now()->format('Y-m-d H:i:s'), 'description' => 'METRO', 'amount' => -90.00,
        'hash' => 'm1', 'is_excluded' => false,
    ]);

    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['summary' => 'ok', 'observations' => []])]],
        ], 200),
    ]);

    app(SpendingAnalyzer::class)->analyze(3);

    Http::assertSent(function ($request) {
        $systemText = $request['system'][0]['text'];
        $userContent = $request['messages'][0]['content'];

        // Merchant data is still shipped to the model as a private signal...
        return str_contains($userContent, 'top_merchants')
            && str_contains($userContent, 'METRO')
            // ...but the system prompt forbids naming merchants and demands category framing.
            && str_contains($systemText, 'NEVER name a merchant')
            && str_contains($systemText, 'CATEGORIES');
    });
});

it('skips the API when there is no categorized spending', function () {
    Http::fake();

    $result = app(SpendingAnalyzer::class)->analyze(3);

    expect($result['observations'])->toBe([]);
    Http::assertNothingSent();
});
