<?php

use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\InvestmentScheduleProjector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    // Freeze "today". Investment history is seeded relative to this instant.
    Carbon::setTestNow('2026-06-14 10:00:00');
    $this->investments = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);
});

afterEach(function () {
    Carbon::setTestNow(null);
});

/** Seed an investment debit on a date (Y-m-d). */
function investTxn(User $user, Category $cat, float $magnitude, string $date, string $description): Transaction
{
    static $seq = 0;
    $seq++;

    return Transaction::factory()->create([
        'user_id' => $user->id,
        'category_id' => $cat->id,
        'transaction_date' => $date.' 10:00:00',
        'description' => $description,
        'amount' => -abs($magnitude),
        'hash' => 'inv'.$seq,
        'is_excluded' => false,
    ]);
}

it('returns investment category ids by name, including descendants', function () {
    $financial = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Financial']);
    $childInvest = Category::factory()->create([
        'user_id' => $this->user->id, 'name' => 'Investments', 'parent_id' => $financial->id,
    ]);
    Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Restaurants']);

    $ids = app(InvestmentScheduleProjector::class)->investmentCategoryIds();

    expect($ids)->toContain($this->investments->id)
        ->and($ids)->toContain($childInvest->id);
});

it('resolves investment category ids by kind when tagged, without the name-token fallback (CAT-INV)', function () {
    // Kind-tagged, name doesn't match the token list at all — must still resolve via kind.
    $rrsp = Category::factory()->kind('investment')->create(['user_id' => $this->user->id, 'name' => 'RRSP Contributions']);
    $child = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'TFSA', 'parent_id' => $rrsp->id]);
    // A name-token match that is NOT kind-tagged must be excluded once kind-tagged categories exist.
    Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Investments']);

    $ids = app(InvestmentScheduleProjector::class)->investmentCategoryIds();

    expect($ids)->toContain($rrsp->id)
        ->and($ids)->toContain($child->id)
        ->and($ids)->toHaveCount(2);
});

it('falls back to name-token matching when no category is kind-tagged investment (CAT-INV)', function () {
    // $this->investments (name "Investments", no kind) seeded in beforeEach — no kind-tagged
    // investment category exists anywhere, so the defensive fallback must still resolve it.
    $ids = app(InvestmentScheduleProjector::class)->investmentCategoryIds();

    expect($ids)->toContain($this->investments->id);
});

it('does not re-project the most recent actual contribution when it lands on today', function () {
    // Monthly $500, the latest posted exactly today (today == lastSeen).
    investTxn($this->user, $this->investments, 500, '2026-04-14', 'RRSP');
    investTxn($this->user, $this->investments, 500, '2026-05-14', 'RRSP');
    investTxn($this->user, $this->investments, 500, '2026-06-14', 'RRSP'); // today's actual, already left the account

    $today = CarbonImmutable::parse('2026-06-14');
    $events = app(InvestmentScheduleProjector::class)->events($today, $today->addMonths(2), $today);

    $dates = collect($events)->pluck('date');
    expect($dates)->not->toContain('2026-06-14')  // the actual must not be projected again
        ->and($dates)->toContain('2026-07-14');    // genuine future contribution still projected
});

it('projects active biweekly contributions forward from the last actual date', function () {
    // Biweekly $300 ending May 30 (15 days before today → active).
    investTxn($this->user, $this->investments, 300, '2026-05-02', 'PLACEMENT NBI');
    investTxn($this->user, $this->investments, 300, '2026-05-16', 'PLACEMENT NBI');
    investTxn($this->user, $this->investments, 300, '2026-05-30', 'PLACEMENT NBI');

    $today = CarbonImmutable::parse('2026-06-14');
    $events = app(InvestmentScheduleProjector::class)->events($today, $today->addDays(60));

    expect($events)->not->toBeEmpty()
        ->and($events[0]['kind'])->toBe('investment')
        ->and($events[0]['amount'])->toBe(-300.0)
        // First future occurrence: May 30 + 14 = June 13 < today; next is June 27.
        ->and($events[0]['date'])->toBe('2026-06-27');
});

it('separates two streams under one merchant by amount', function () {
    foreach (['2026-05-02', '2026-05-16', '2026-05-30'] as $d) {
        investTxn($this->user, $this->investments, 75, $d, 'PLACEMENT NBC');
        investTxn($this->user, $this->investments, 60, $d, 'PLACEMENT NBC');
    }

    $today = CarbonImmutable::parse('2026-06-14');
    $events = app(InvestmentScheduleProjector::class)->events($today, $today->addDays(30));

    $amounts = collect($events)->pluck('amount')->unique()->sort()->values()->all();
    expect($amounts)->toContain(-75.0)
        ->and($amounts)->toContain(-60.0);
});

it('returns no events and no active keys for a stale stream', function () {
    // Biweekly stream that stopped in early April → lastSeen ~70 days before today
    // (> canonicalDays(bi_weekly)*2 = 28) → stale.
    investTxn($this->user, $this->investments, 300, '2026-03-10', 'PLACEMENT NBI');
    investTxn($this->user, $this->investments, 300, '2026-03-24', 'PLACEMENT NBI');
    investTxn($this->user, $this->investments, 300, '2026-04-07', 'PLACEMENT NBI');

    // Anchor (latest available data) is well AFTER the stream's last contribution
    // (April 7) — i.e. newer data exists but this stream stopped contributing, so
    // it is genuinely paused: lastSeen->diffInDays(anchor) = 68 > 28.
    $today = CarbonImmutable::parse('2026-06-14');
    $anchor = CarbonImmutable::parse('2026-06-14');
    $projector = app(InvestmentScheduleProjector::class);

    expect($projector->events($today, $today->addDays(60), $anchor))->toBe([])
        ->and($projector->activeStreamKeys($today, $anchor))->toBe([])
        ->and($projector->activeStreamTransactionIds($today, $anchor))->toBe([]);
});

it('returns active keys and ids in lockstep with events', function () {
    $rows = collect([
        investTxn($this->user, $this->investments, 300, '2026-05-02', 'PLACEMENT NBI'),
        investTxn($this->user, $this->investments, 300, '2026-05-16', 'PLACEMENT NBI'),
        investTxn($this->user, $this->investments, 300, '2026-05-30', 'PLACEMENT NBI'),
    ]);

    $today = CarbonImmutable::parse('2026-06-14');
    $anchor = CarbonImmutable::parse('2026-05-30');
    $projector = app(InvestmentScheduleProjector::class);

    expect($projector->activeStreamKeys($today, $anchor))->toContain('placement nbi')
        ->and($projector->activeStreamTransactionIds($today, $anchor))
        ->toEqualCanonicalizing($rows->pluck('id')->map(fn ($id) => (int) $id)->all())
        ->and($projector->events($today, $today->addDays(60), $anchor))->not->toBeEmpty();
});

it('returns no events when there is no investment history', function () {
    $today = CarbonImmutable::parse('2026-06-14');

    expect(app(InvestmentScheduleProjector::class)->events($today, $today->addDays(60)))->toBe([]);
});
