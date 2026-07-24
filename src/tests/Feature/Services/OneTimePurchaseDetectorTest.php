<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\OneTimePurchaseDetector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    Carbon::setTestNow('2026-06-14 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow(null);
});

function detectTxn(User $user, ?Category $category, float $signed, string $date, string $description = 'MERCHANT', array $overrides = []): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description,
        'amount' => $signed,
        'hash' => 'det'.$seq,
        'is_excluded' => false,
    ], $overrides));
}

it('detects a large outlier inside an explicit window', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23'] as $d) {
        detectTxn($this->user, $shopping, -40, $d, 'CAFE');
    }
    detectTxn($this->user, $shopping, -2500, '2026-05-10', 'NUASIS FURNITURE');

    $hits = app(OneTimePurchaseDetector::class)
        ->detect(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'));

    expect($hits->pluck('merchant'))->toContain('NUASIS FURNITURE')
        ->and($hits->pluck('merchant'))->not->toContain('CAFE');
});

it('is window-parameterized — a spike outside the passed window is not detected', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    // Small everyday noise inside the window keeps the median low.
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23'] as $d) {
        detectTxn($this->user, $shopping, -40, $d, 'CAFE');
    }
    detectTxn($this->user, $shopping, -3000, '2026-03-10', 'OUT OF WINDOW');
    detectTxn($this->user, $shopping, -2500, '2026-05-10', 'IN WINDOW');

    $hits = app(OneTimePurchaseDetector::class)
        ->detect(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'));

    expect($hits->pluck('merchant'))->toContain('IN WINDOW')
        ->and($hits->pluck('merchant'))->not->toContain('OUT OF WINDOW');
});

it('skips fixed-expense-category, money-movement, frequent-merchant, and active-recurring rows', function () {
    $rentCat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
    $investCat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);

    FixedExpense::factory()->forCategory($rentCat)->create(['amount' => 1675, 'frequency' => 'monthly', 'is_active' => true]);
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id, 'merchant_key' => 'acme insurance',
        'cadence' => 'monthly', 'expected_amount' => 900, 'status' => 'active',
    ]);

    // Small everyday noise keeps the median low so the $200 floor governs the bar.
    foreach (['2026-05-05', '2026-05-07', '2026-05-14', '2026-05-21', '2026-05-25', '2026-05-28'] as $d) {
        detectTxn($this->user, $shopping, -40, $d, 'CAFE');
    }

    detectTxn($this->user, $rentCat, -1675, '2026-05-01', 'LANDLORD');
    detectTxn($this->user, $investCat, -900, '2026-05-02', 'PLACEMENT');
    detectTxn($this->user, $shopping, -900, '2026-05-03', 'ACME INSURANCE');
    detectTxn($this->user, $shopping, -300, '2026-05-04', 'COSTCO');
    detectTxn($this->user, $shopping, -300, '2026-05-11', 'COSTCO');
    detectTxn($this->user, $shopping, -300, '2026-05-18', 'COSTCO');
    detectTxn($this->user, $shopping, -2000, '2026-05-12', 'NUASIS');

    $merchants = app(OneTimePurchaseDetector::class)
        ->detect(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'))
        ->pluck('merchant');

    expect($merchants)->toContain('NUASIS')
        ->and($merchants)->not->toContain('LANDLORD')
        ->and($merchants)->not->toContain('PLACEMENT')
        ->and($merchants)->not->toContain('ACME INSURANCE')
        ->and($merchants)->not->toContain('COSTCO');
});

it('respects the dollar floor and median multiple', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    detectTxn($this->user, $shopping, -50, '2026-05-03', 'STORE A');
    detectTxn($this->user, $shopping, -60, '2026-05-10', 'STORE B');
    detectTxn($this->user, $shopping, -150, '2026-05-17', 'STORE C ONE OFF'); // < $200 floor

    $hits = app(OneTimePurchaseDetector::class)
        ->detect(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'));

    expect($hits)->toHaveCount(0);
});

it('computes the median over ALL rows regardless of excludeCategoryIds', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $investCat = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    // Elevated everyday spend (median $300) plus big investment debits.
    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23'] as $d) {
        detectTxn($this->user, $shopping, -300, $d, 'BIG STORE '.$d);
    }
    detectTxn($this->user, $investCat, -940, '2026-05-06', 'PLACEMENT NBI');
    detectTxn($this->user, $investCat, -940, '2026-05-20', 'PLACEMENT NBI');
    // A $1,000 buy: clears the $200 floor but NOT 4× the full-population median (~$300 → $1,200 bar).
    detectTxn($this->user, $shopping, -1000, '2026-05-12', 'MIDSIZE');

    $detector = app(OneTimePurchaseDetector::class);
    $start = CarbonImmutable::parse('2026-05-01');
    $end = CarbonImmutable::parse('2026-05-31');

    $withoutExclude = $detector->detect($start, $end)->pluck('merchant')->all();
    $withExclude = $detector->detect($start, $end, [$investCat->id])->pluck('merchant')->all();

    // The midsize row is excluded by the (undeflated) bar in BOTH calls — the bar
    // did not move when investments were excluded from candidates.
    expect($withoutExclude)->not->toContain('MIDSIZE')
        ->and($withExclude)->not->toContain('MIDSIZE');
});

it('never flags rows whose category is in excludeCategoryIds, but still flags others above the all-rows bar', function () {
    $shopping = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Shopping']);
    $hobbies = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Hobbies']);

    foreach (['2026-05-02', '2026-05-09', '2026-05-16', '2026-05-23'] as $d) {
        detectTxn($this->user, $shopping, -40, $d, 'CAFE');
    }
    detectTxn($this->user, $hobbies, -2000, '2026-05-08', 'BIG HOBBY BUY');
    detectTxn($this->user, $shopping, -2500, '2026-05-12', 'NUASIS');

    $merchants = app(OneTimePurchaseDetector::class)
        ->detect(CarbonImmutable::parse('2026-05-01'), CarbonImmutable::parse('2026-05-31'), [$hobbies->id])
        ->pluck('merchant');

    expect($merchants)->toContain('NUASIS')
        ->and($merchants)->not->toContain('BIG HOBBY BUY');
});
