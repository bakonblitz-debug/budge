<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\CategorySpikeDetector;
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

function spikeTxn(User $user, ?Category $category, float $signed, string $date, string $description = 'MERCHANT'): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category?->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description,
        'amount' => $signed,
        'hash' => 'spk'.$seq,
        'is_excluded' => false,
    ]);
}

/** A category whose name classifies as discretionary. */
function discretionaryCategory(User $user, string $name = 'Shopping'): Category
{
    return Category::factory()->create(['user_id' => $user->id, 'name' => $name]);
}

it('strips the per-month excess of a discretionary spree over its prior baseline', function () {
    $shopping = discretionaryCategory($this->user);

    // Two prior months of normal spend → baseline median $100 (≥ $50 guard).
    spikeTxn($this->user, $shopping, -100, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'NORMAL FEB');

    // Window (Mar–May): two spree months + one normal month.
    spikeTxn($this->user, $shopping, -600, '2026-03-10', 'RONA');
    spikeTxn($this->user, $shopping, -400, '2026-03-20', 'RONA');   // Mar = $1000
    spikeTxn($this->user, $shopping, -800, '2026-04-12', 'JYSK');   // Apr = $800
    spikeTxn($this->user, $shopping, -90, '2026-05-03', 'NORMAL');  // May = $90 (< bar)

    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-05-31'))
        ->firstWhere('category', 'Shopping');

    // Mar excess (1000-100=900) + Apr excess (800-100=700) = 1600; May not flagged.
    expect($row['baseline_monthly'])->toBe(100.0)
        ->and($row['window_spend'])->toBe(1890.0)
        ->and($row['stripped_excess'])->toBe(1600.0)
        ->and(collect($row['months'])->pluck('ym')->all())->toBe(['2026-03', '2026-04']);
});

it('excludes excludeTransactionIds from the in-window bucket so a one-time row is not double-counted', function () {
    $shopping = discretionaryCategory($this->user);
    spikeTxn($this->user, $shopping, -100, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'NORMAL FEB');

    // Mar = a kept $700 buy + a $250 one-time row (already removed from the forecaster's total).
    spikeTxn($this->user, $shopping, -700, '2026-03-10', 'RONA');
    $oneTime = spikeTxn($this->user, $shopping, -250, '2026-03-18', 'WINNERS ONE-OFF');

    $detector = app(CategorySpikeDetector::class);
    $start = CarbonImmutable::parse('2026-03-01');
    $end = CarbonImmutable::parse('2026-03-31');

    $raw = $detector->detect($start, $end)->firstWhere('category', 'Shopping');
    $excluded = $detector->detect($start, $end, [], [$oneTime->id])->firstWhere('category', 'Shopping');

    // Raw Mar bucket = $950 → excess $850. With the one-time id excluded, bucket = $700 → excess $600.
    expect($raw['stripped_excess'])->toBe(850.0)
        ->and($excluded['stripped_excess'])->toBe(600.0);
});

it('keeps the baseline RAW — excludeTransactionIds never lowers the prior-month median', function () {
    $shopping = discretionaryCategory($this->user);

    // Baseline Jan = $60 + $40 (two rows) = $100; Feb = $100 → median $100.
    spikeTxn($this->user, $shopping, -60, '2026-01-10', 'JAN A');
    $baselineRow = spikeTxn($this->user, $shopping, -40, '2026-01-20', 'JAN B');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'FEB');

    // A spree month so the category surfaces.
    spikeTxn($this->user, $shopping, -1000, '2026-03-10', 'RONA');

    // Passing a BASELINE row's id must NOT change the baseline (exclusion is in-window only).
    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'), [], [$baselineRow->id])
        ->firstWhere('category', 'Shopping');

    expect($row['baseline_monthly'])->toBe(100.0)
        ->and($row['stripped_excess'])->toBe(900.0); // 1000 - 100, baseline untouched
});

it('flags a big buy that lands in a PARTIAL leading window month', function () {
    $shopping = discretionaryCategory($this->user);
    spikeTxn($this->user, $shopping, -100, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'NORMAL FEB');

    // Window starts mid-March; the big buy is on Mar 20 (partial month).
    spikeTxn($this->user, $shopping, -1000, '2026-03-20', 'RONA');

    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-15'), CarbonImmutable::parse('2026-04-30'))
        ->firstWhere('category', 'Shopping');

    expect($row)->not->toBeNull()
        ->and($row['stripped_excess'])->toBe(900.0)
        ->and(collect($row['months'])->pluck('ym')->all())->toBe(['2026-03']);
});

it('does not flag a low partial trailing month — because its spend is below the bar', function () {
    $shopping = discretionaryCategory($this->user);
    spikeTxn($this->user, $shopping, -100, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'NORMAL FEB');

    spikeTxn($this->user, $shopping, -1000, '2026-03-10', 'RONA'); // spree Mar
    spikeTxn($this->user, $shopping, -90, '2026-05-03', 'NORMAL'); // tiny 7-day trailing stub

    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-05-07'))
        ->firstWhere('category', 'Shopping');

    // May ($90) is below 1.5×$100=$150 → not flagged; only Mar excess stripped.
    expect($row['stripped_excess'])->toBe(900.0)
        ->and(collect($row['months'])->pluck('ym')->all())->toBe(['2026-03']);
});

it('gates out a discretionary category whose baseline is below the $50 floor', function () {
    $other = discretionaryCategory($this->user, 'Other'); // discretionary per the keyword list

    // Sparse, low baseline: median ~$42.5 < $50.
    spikeTxn($this->user, $other, -43, '2026-01-15', 'BARBER');
    spikeTxn($this->user, $other, -42, '2026-02-15', 'SAQ');

    // In-window months ~$180 would clear 1.5×median AND the $50 excess floor…
    spikeTxn($this->user, $other, -180, '2026-03-10', 'STUFF');
    spikeTxn($this->user, $other, -180, '2026-04-10', 'STUFF');

    $result = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30'));

    // …but the baseline ($42.5) is below SPIKE_MIN_EXCESS ($50) → never a candidate.
    expect($result->firstWhere('category', 'Other'))->toBeNull();
});

it('never strips a non-discretionary category, even with a clear spree', function (string $name) {
    $category = Category::factory()->create(['user_id' => $this->user->id, 'name' => $name]);
    spikeTxn($this->user, $category, -300, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $category, -300, '2026-02-15', 'NORMAL FEB');
    spikeTxn($this->user, $category, -3000, '2026-03-10', 'BIG SPREE');
    spikeTxn($this->user, $category, -3000, '2026-04-10', 'BIG SPREE');

    $result = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30'));

    expect($result->firstWhere('category', $name))->toBeNull();
})->with(['Groceries', 'Transport', 'Transfers', 'Investments']);

it('strips only the spiked month, leaving normal months whole', function () {
    $shopping = discretionaryCategory($this->user);
    spikeTxn($this->user, $shopping, -100, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'NORMAL FEB');

    spikeTxn($this->user, $shopping, -100, '2026-03-10', 'NORMAL'); // normal
    spikeTxn($this->user, $shopping, -1000, '2026-04-10', 'SPREE'); // 10× spike
    spikeTxn($this->user, $shopping, -100, '2026-05-10', 'NORMAL'); // normal

    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-05-31'))
        ->firstWhere('category', 'Shopping');

    expect($row['stripped_excess'])->toBe(900.0) // only Apr (1000-100)
        ->and(collect($row['months'])->pluck('ym')->all())->toBe(['2026-04']);
});

it('honors the per-month $50 excess floor', function () {
    $shopping = discretionaryCategory($this->user);
    // Baseline $60 so 1.5×=$90 < baseline+50=$110 → the $50 floor is the binding gate.
    spikeTxn($this->user, $shopping, -60, '2026-01-15', 'NORMAL JAN');
    spikeTxn($this->user, $shopping, -60, '2026-02-15', 'NORMAL FEB');

    spikeTxn($this->user, $shopping, -100, '2026-03-10', 'CLEARS MULT ONLY'); // excess $40 < $50
    spikeTxn($this->user, $shopping, -120, '2026-04-10', 'CLEARS BOTH');      // excess $60 ≥ $50

    $row = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-04-30'))
        ->firstWhere('category', 'Shopping');

    expect($row['stripped_excess'])->toBe(60.0) // only Apr
        ->and(collect($row['months'])->pluck('ym')->all())->toBe(['2026-04']);
});

it('skips a category with fewer than two prior months of baseline data', function () {
    $shopping = discretionaryCategory($this->user);
    // Only ONE prior month with spend → cannot establish a baseline.
    spikeTxn($this->user, $shopping, -100, '2026-02-15', 'ONLY ONE MONTH');
    spikeTxn($this->user, $shopping, -1000, '2026-03-10', 'SPREE');

    $result = app(CategorySpikeDetector::class)
        ->detect(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

    expect($result->firstWhere('category', 'Shopping'))->toBeNull();
});
