<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use App\Services\RecurringDetector;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

/** Create an expense for a merchant on a given date. */
function charge(int $userId, string $merchant, float $amount, Carbon $date): void
{
    Transaction::factory()->create([
        'user_id' => $userId,
        'description' => $merchant,
        'merchant_name' => $merchant,
        'amount' => -abs($amount),
        'transaction_date' => $date->toDateTimeString(),
        'hash' => bin2hex(random_bytes(16)),
    ]);
}

/** Create a categorized expense for a merchant on a given date. */
function categorizedCharge(int $userId, string $merchant, float $amount, Carbon $date, int $categoryId): void
{
    Transaction::factory()->create([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'description' => $merchant,
        'merchant_name' => $merchant,
        'amount' => -abs($amount),
        'transaction_date' => $date->toDateTimeString(),
        'hash' => bin2hex(random_bytes(16)),
    ]);
}

/** Seed a bi-weekly stream of $count charges ending today. */
function biweeklyStream(int $userId, string $merchant, float $amount, int $count, ?int $categoryId = null): void
{
    $now = Carbon::now();
    for ($i = 0; $i < $count; $i++) {
        $date = $now->copy()->subDays($i * 14);
        if ($categoryId !== null) {
            categorizedCharge($userId, $merchant, $amount, $date, $categoryId);
        } else {
            charge($userId, $merchant, $amount, $date);
        }
    }
}

it('detects a monthly subscription', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'NETFLIX', 16.49, $now->copy()->subDays($daysAgo));
    }

    expect(app(RecurringDetector::class)->detect())->toBe(1);

    $series = RecurringSeries::where('merchant_key', 'netflix')->first();
    expect($series)->not->toBeNull()
        ->and($series->cadence)->toBe('monthly')
        ->and((float) $series->expected_amount)->toBe(16.49)
        ->and($series->occurrences)->toBe(4)
        ->and($series->status)->toBe('active')
        ->and($series->amount_increased)->toBeFalse();
});

it('detects a bi-weekly cadence', function () {
    $now = Carbon::now();
    foreach ([42, 28, 14, 0] as $daysAgo) {
        charge($this->user->id, 'GYM', 25.00, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'gym')->first()->cadence)->toBe('bi_weekly');
});

it('flags a price increase on the latest charge', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30] as $daysAgo) {
        charge($this->user->id, 'SPOTIFY', 9.99, $now->copy()->subDays($daysAgo));
    }
    charge($this->user->id, 'SPOTIFY', 11.99, $now->copy()); // latest is higher

    app(RecurringDetector::class)->detect();

    $series = RecurringSeries::where('merchant_key', 'spotify')->first();
    expect($series->amount_increased)->toBeTrue()
        ->and((float) $series->last_amount)->toBe(11.99);
});

it('ignores merchants with too few charges', function () {
    $now = Carbon::now();
    charge($this->user->id, 'ONE OFF', 50.00, $now->copy()->subDays(30));
    charge($this->user->id, 'ONE OFF', 50.00, $now->copy());

    expect(app(RecurringDetector::class)->detect())->toBe(0);
    expect(RecurringSeries::count())->toBe(0);
});

it('ignores irregular intervals', function () {
    $now = Carbon::now();
    charge($this->user->id, 'RANDOM', 20.00, $now->copy()->subDays(53));
    charge($this->user->id, 'RANDOM', 20.00, $now->copy()->subDays(50));
    charge($this->user->id, 'RANDOM', 20.00, $now->copy());

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'random')->exists())->toBeFalse();
});

it('collapses reference-noisy charges into one monthly series', function () {
    // Real case: Spotify stamps a unique alphanumeric ref token + trailing geo
    // on every charge, so naive grouping yields 9 groups of 1 and detects nothing.
    $now = Carbon::now();
    $refs = ['P36FB9F285', 'P37DDDA545', 'P3812AB991', 'P39CC0E712', 'P40FA1D003',
        'P41BE2C884', 'P42AD3F665', 'P43CE4A226', 'P44DF5B117'];
    foreach ($refs as $i => $ref) {
        charge($this->user->id, "SPOTIFY {$ref} STOCKHOLM SWE", 14.59, $now->copy()->subDays(($count = count($refs)) - 1 - $i)->subDays((($count - 1 - $i)) * 29));
    }

    expect(app(RecurringDetector::class)->detect())->toBe(1);

    $series = RecurringSeries::query()->where('status', 'active')->get();
    expect($series)->toHaveCount(1)
        ->and($series->first()->cadence)->toBe('monthly')
        ->and($series->first()->occurrences)->toBe(9)
        ->and((float) $series->first()->expected_amount)->toBe(14.59);
});

it('does not over-merge genuinely different merchants', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'NETFLIX MONTREAL QC', 16.49, $now->copy()->subDays($daysAgo));
    }
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'DISNEY PLUS MONTREAL QC', 11.99, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::query()->where('status', 'active')->count())->toBe(2);
});

it('does not resurrect a dismissed series on rescan', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'NETFLIX', 16.49, $now->copy()->subDays($daysAgo));
    }
    $detector = app(RecurringDetector::class);
    $detector->detect();

    $series = RecurringSeries::where('merchant_key', 'netflix')->first();
    $series->update(['status' => 'dismissed']);

    $detector->detect();

    $series->refresh();
    expect($series->status)->toBe('dismissed');
    expect(RecurringSeries::where('merchant_key', 'netflix')->count())->toBe(1);
});

it('does not resurrect or end a converted series on rescan', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'NETFLIX', 16.49, $now->copy()->subDays($daysAgo));
    }
    $detector = app(RecurringDetector::class);
    $detector->detect();

    $series = RecurringSeries::where('merchant_key', 'netflix')->first();
    $series->update(['status' => 'converted']);

    $detector->detect();

    $series->refresh();
    expect($series->status)->toBe('converted');
    expect(RecurringSeries::where('merchant_key', 'netflix')->count())->toBe(1);
});

it('keeps a manual series alive across a rescan', function () {
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'my hand-added bill',
        'status' => 'active',
    ]);

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('active');
});

it('reconciles a manual series whose merchant now matches a detected group', function () {
    // Blizzard-like, but with a full regular run so it lands in the main
    // detected-group path: the manual series' key must sync last_seen/
    // last_amount/occurrences/status from the real transactions, not sit
    // frozen at whatever it had when it was hand-added.
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'blizzard',
        'cadence' => 'monthly',
        'expected_amount' => 15.99,
        'occurrences' => 0,
        'status' => 'active',
    ]);

    $now = Carbon::now();
    foreach ([60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'BLIZZARD', 15.99, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    $manual->refresh();
    expect($manual->status)->toBe('active')
        ->and($manual->occurrences)->toBe(3)
        ->and($manual->last_seen_at->toDateString())->toBe($now->toDateString());
});

it('ends a manual series whose real charges stopped long ago', function () {
    // Below MIN_OCCURRENCES so it never enters the main detected-group loop
    // and must be caught by manual reconciliation instead.
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'blizzard',
        'cadence' => 'monthly',
        'status' => 'active',
    ]);

    $now = Carbon::now();
    charge($this->user->id, 'BLIZZARD', 15.99, $now->copy()->subMonths(5));
    charge($this->user->id, 'BLIZZARD', 15.99, $now->copy()->subMonths(4));

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('ended');
});

it('ends a manual series stale by its observed interval despite a wrong stored cadence', function () {
    // Live Blizzard case: stored bi_monthly (60d) but real charges ran monthly
    // (~30d apart) and stopped 82 days ago. Trusting the stored cadence needs
    // >120d and wrongly keeps it active; the observed 30d interval ends it.
    // Two charges (< MIN_OCCURRENCES) so it bypasses the main detect loop and
    // must be resolved by reconcileManual.
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'blizzard',
        'cadence' => 'bi_monthly',
        'status' => 'active',
    ]);

    $now = Carbon::now();
    charge($this->user->id, 'BLIZZARD', 15.99, $now->copy()->subDays(112));
    charge($this->user->id, 'BLIZZARD', 15.99, $now->copy()->subDays(82));

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('ended');
});

it('keeps a manual series active when its observed interval is still fresh', function () {
    // Spotify-like: monthly charges, last one ~21 days ago — within 2 observed
    // intervals, so it stays active even though the stored cadence is wrong.
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'spotify',
        'cadence' => 'bi_monthly',
        'status' => 'active',
    ]);

    $now = Carbon::now();
    charge($this->user->id, 'SPOTIFY', 11.99, $now->copy()->subDays(51));
    charge($this->user->id, 'SPOTIFY', 11.99, $now->copy()->subDays(21));

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('active');
});

it('keeps grace for a freshly-added manual series with no charges yet', function () {
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'brand new sub',
        'cadence' => 'monthly',
        'occurrences' => 0,
        'status' => 'active',
    ]);

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('active');
});

it('ends a manual series added long ago that never got a single matching charge', function () {
    $manual = RecurringSeries::factory()->manual()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'ghost sub',
        'cadence' => 'monthly',
        'occurrences' => 0,
        'status' => 'active',
        'created_at' => Carbon::now()->subMonths(3),
    ]);

    app(RecurringDetector::class)->detect();

    expect($manual->fresh()->status)->toBe('ended');
});

it('detects a bi-monthly cadence', function () {
    $now = Carbon::now();
    foreach ([120, 60, 0] as $daysAgo) {
        charge($this->user->id, 'BIMONTHLY', 40.00, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'bimonthly')->first()->cadence)->toBe('bi_monthly');
});

it('detects a semi-annual cadence', function () {
    $now = Carbon::now();
    foreach ([364, 182, 0] as $daysAgo) {
        charge($this->user->id, 'SEMIANNUAL', 99.00, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'semiannual')->first()->cadence)->toBe('semi_annual');
});

it('detects a yearly cadence at three occurrences', function () {
    $now = Carbon::now();
    foreach ([730, 365, 0] as $daysAgo) {
        charge($this->user->id, 'YEARLY', 120.00, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'yearly')->first()->cadence)->toBe('yearly');
});

it('surfaces near-miss groups as candidates and excludes tracked series', function () {
    $now = Carbon::now();
    // Two stable-amount charges = near miss (below the 3 min for auto-detect).
    foreach ([30, 0] as $daysAgo) {
        charge($this->user->id, 'MAYBE SUB', 7.99, $now->copy()->subDays($daysAgo));
    }
    // An already-active series should NOT appear as a candidate.
    foreach ([90, 60, 30, 0] as $daysAgo) {
        charge($this->user->id, 'NETFLIX', 16.49, $now->copy()->subDays($daysAgo));
    }

    $detector = app(RecurringDetector::class);
    $detector->detect();

    $candidates = $detector->candidates();
    $keys = array_column($candidates, 'merchant_key');

    expect($keys)->toContain('maybe sub')
        ->and($keys)->not->toContain('netflix');

    $maybe = collect($candidates)->firstWhere('merchant_key', 'maybe sub');
    expect($maybe['occurrences'])->toBe(2)
        ->and((float) $maybe['expected_amount'])->toBe(7.99)
        ->and($maybe)->toHaveKey('suggested_cadence')
        ->and($maybe)->toHaveKey('samples');
});

it('excludes dismissed series from candidates', function () {
    $now = Carbon::now();
    foreach ([30, 0] as $daysAgo) {
        charge($this->user->id, 'DISMISSED CO', 5.00, $now->copy()->subDays($daysAgo));
    }
    RecurringSeries::factory()->dismissed()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'dismissed co',
    ]);

    $candidates = app(RecurringDetector::class)->candidates();

    expect(array_column($candidates, 'merchant_key'))->not->toContain('dismissed co');
});

it('detects an active monthly sub that skips two billing months (YouTube-like)', function () {
    // Real case: charged around the 18th most months but skips a couple —
    // raw gaps [31,28,61,31,30,61,31] have CV ~0.36 (over the 0.30 gate) and
    // used to reject the whole series, wrongly ending an active subscription
    // (SUB-2). Skip-folding the 61-day gaps (2 missed cycles) back to ~30
    // days brings it well under the gate.
    $now = Carbon::now();
    $gaps = [31, 28, 61, 31, 30, 61, 31];
    $daysAgo = [0];
    foreach (array_reverse($gaps) as $gap) {
        $daysAgo[] = end($daysAgo) + $gap;
    }
    foreach ($daysAgo as $d) {
        charge($this->user->id, 'YOUTUBE PREMIUM', 14.99, $now->copy()->subDays($d));
    }

    expect(app(RecurringDetector::class)->detect())->toBe(1);

    $series = RecurringSeries::where('merchant_key', 'youtube premium')->first();
    expect($series)->not->toBeNull()
        ->and($series->cadence)->toBe('monthly')
        ->and($series->occurrences)->toBe(8)
        ->and($series->status)->toBe('active');
});

it('detects a monthly sub with one skipped month folded from four charges', function () {
    // [30,30,60]: the 60-day gap is one skipped cycle, folded to 30.
    $now = Carbon::now();
    foreach ([120, 90, 60, 0] as $daysAgo) {
        charge($this->user->id, 'FOLD FOUR', 9.99, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    $series = RecurringSeries::where('merchant_key', 'fold four')->first();
    expect($series)->not->toBeNull()
        ->and($series->cadence)->toBe('monthly')
        ->and($series->status)->toBe('active');
});

it('still rejects a 3-charge sub with one skipped month (SUB-3, accepted limitation)', function () {
    // [30,60]: folding needs >=2 un-folded intervals to trust it; a 3-charge
    // series only ever has 2 intervals total, so it can never satisfy that
    // guard. Documented, deliberately not fixed here (see analyze()).
    $now = Carbon::now();
    foreach ([90, 60, 0] as $daysAgo) {
        charge($this->user->id, 'THREE SKIP', 9.99, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'three skip')->exists())->toBeFalse();
});

it('rejects a variable-amount weekly grocery run (amount gate)', function () {
    $now = Carbon::now();
    $amounts = [110.00, 100.00, 25.00, 20.00];
    foreach ([21, 14, 7, 0] as $i => $daysAgo) {
        charge($this->user->id, 'WEEKLY GROCERY', $amounts[$i], $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'weekly grocery')->exists())->toBeFalse();
});

it('rejects a daily coffee run (no cadence band below weekly)', function () {
    $now = Carbon::now();
    foreach ([2, 1, 0] as $daysAgo) {
        charge($this->user->id, 'DAILY COFFEE', 4.50, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    expect(RecurringSeries::where('merchant_key', 'daily coffee')->exists())->toBeFalse();
});

it('marks an old series as ended', function () {
    $now = Carbon::now();
    foreach ([200, 170, 140] as $daysAgo) {
        charge($this->user->id, 'OLD SUB', 12.00, $now->copy()->subDays($daysAgo));
    }

    app(RecurringDetector::class)->detect();

    $series = RecurringSeries::where('merchant_key', 'old sub')->first();
    expect($series)->not->toBeNull()
        ->and($series->status)->toBe('ended');
});

it('surfaces a bi-weekly investment stream as a fixed-expense candidate', function () {
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    biweeklyStream($this->user->id, 'PLACEMENT NBI', 300.00, 6, $transfers->id);

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();
    $merchants = array_map(fn ($c) => $c['merchant'], $candidates);

    expect($merchants)->toContain('Placement Nbi');

    $c = collect($candidates)->firstWhere('merchant', 'Placement Nbi');
    expect((float) $c['amount'])->toBe(300.00)
        ->and($c['suggested_cadence'])->toBe('bi_weekly')
        ->and($c['occurrences'])->toBe(6)
        ->and($c['category_id'])->toBe($transfers->id)
        ->and($c['category_name'])->toBe('Transfers')
        ->and($c)->toHaveKey('sample')
        ->and(count($c['sample']))->toBeLessThanOrEqual(3);
});

it('separates same-merchant streams by amount into distinct candidates', function () {
    // The NBC $75 and $60 streams share a merchant name; grouping by merchant
    // alone makes them irregular. Grouping by merchant+amount keeps them apart.
    biweeklyStream($this->user->id, 'PLACEMENT NBC', 75.00, 6);
    biweeklyStream($this->user->id, 'PLACEMENT NBC', 60.00, 6);

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();
    $nbc = collect($candidates)->where('merchant', 'Placement Nbc');

    expect($nbc)->toHaveCount(2);
    $amounts = $nbc->map(fn ($c) => (float) $c['amount'])->sort()->values()->all();
    expect($amounts)->toBe([60.00, 75.00]);
});

it('does not suggest a one-time lump sum', function () {
    charge($this->user->id, 'PLACEMENT', 8000.00, Carbon::now());

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();

    expect(array_map(fn ($c) => $c['merchant'], $candidates))->not->toContain('Placement');
});

it('includes transfer-category charges in fixed-expense candidates', function () {
    // Regression guard: transfers/investments must NOT be filtered out — they
    // are the whole point of this list.
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    biweeklyStream($this->user->id, 'PLACEMENT NBI', 300.00, 5, $transfers->id);

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();

    expect(array_map(fn ($c) => $c['merchant'], $candidates))->toContain('Placement Nbi');
});

it('excludes a stream already tracked as an active fixed expense', function () {
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    biweeklyStream($this->user->id, 'PLACEMENT NBI', 300.00, 6, $transfers->id);

    FixedExpense::factory()->forCategory($transfers)->create([
        'name' => 'Placement Nbi',
        'is_active' => true,
    ]);

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();

    expect(array_map(fn ($c) => $c['merchant'], $candidates))->not->toContain('Placement Nbi');
});

it('excludes a stream already tracked as an active recurring series', function () {
    biweeklyStream($this->user->id, 'PLACEMENT NBI', 300.00, 6);

    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'placement nbi',
        'status' => 'active',
    ]);

    $candidates = app(RecurringDetector::class)->fixedExpenseCandidates();

    expect(array_map(fn ($c) => $c['merchant'], $candidates))->not->toContain('Placement Nbi');
});
