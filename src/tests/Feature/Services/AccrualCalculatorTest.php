<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\AccrualCalculator;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->category = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Rent']);
});

function rentExpense(User $user, Category $category, array $overrides = []): FixedExpense
{
    return FixedExpense::factory()->forCategory($category)->create(array_merge([
        'name' => 'Rent Payment',
        'amount' => 300,
        'frequency' => 'monthly',
        'due_day' => 15,
        'start_date' => '2026-06-01',
        'is_active' => true,
    ], $overrides));
}

/**
 * Create a transaction in the given category whose merchant string matches the
 * expense name (so the accrual merchant-matcher attributes it to the expense).
 *
 * @param  array<string, mixed>  $overrides
 */
function paymentFor(User $user, FixedExpense $expense, float $amount, string $date, array $overrides = []): Transaction
{
    return Transaction::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => $expense->category_id,
        'merchant_name' => $expense->name,
        'transaction_date' => $date,
        'amount' => -abs($amount),
        'is_excluded' => false,
    ], $overrides));
}

it('shows a lump-sum monthly bill as upcoming before its due day, not behind', function () {
    $rent = rentExpense($this->user, $this->category);

    // June 10: before the 15th, nothing paid yet — the daily proration has run
    // ahead of a payment that simply isn't due. This is not "behind".
    $row = app(AccrualCalculator::class)->computeOne($rent, CarbonImmutable::parse('2026-06-10'));

    expect($row['spent'])->toBe(0.0)
        ->and($row['variance'])->toBeLessThan(0.0)
        ->and($row['status'])->toBe('upcoming');
});

it('shows behind once the due day has passed and the bill is still unpaid', function () {
    $rent = rentExpense($this->user, $this->category);

    // June 20: past the 15th and still nothing paid — a genuine miss.
    $row = app(AccrualCalculator::class)->computeOne($rent, CarbonImmutable::parse('2026-06-20'));

    expect($row['status'])->toBe('behind');
});

it('does not mask a paid lump-sum bill (stays on_track/ahead when paid)', function () {
    $rent = rentExpense($this->user, $this->category);
    paymentFor($this->user, $rent, 300, '2026-06-05 10:00:00');

    $row = app(AccrualCalculator::class)->computeOne($rent, CarbonImmutable::parse('2026-06-10'));

    expect($row['status'])->not->toBe('upcoming')
        ->and($row['status'])->not->toBe('behind');
});

it('leaves expenses without a due day on the standard behind status', function () {
    $rent = rentExpense($this->user, $this->category, ['due_day' => null]);

    $row = app(AccrualCalculator::class)->computeOne($rent, CarbonImmutable::parse('2026-06-10'));

    expect($row['status'])->toBe('behind');
});

// --- Period scoping (Bug 1: accrual must reset each billing period) ---------

it('resets accrual to the current period instead of accruing since start_date', function () {
    // Rent started 2026-05-01; as of mid-June the lifetime bug accrued ~$2476.
    // Correct behavior: only the current (June) period accrues.
    $rent = rentExpense($this->user, $this->category, [
        'amount' => 1675,
        'due_day' => null,
        'start_date' => '2026-05-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($rent, CarbonImmutable::parse('2026-06-14'));

    // Period [2026-06-01, 2026-07-01): 30 days, 14 elapsed -> 1675 * 14/30.
    expect($row['accrued'])->toBe(781.67)
        ->and($row['accrued'])->toBeLessThanOrEqual(1675.0)
        ->and($row['start_date'])->toBe('2026-06-01');
});

it('accrues exactly the full amount across a 31-day month', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 1000,
        'due_day' => null,
        'start_date' => '2026-07-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-07-31'));

    expect($row['accrued'])->toBe(1000.0)
        ->and($row['days_elapsed'])->toBe(31);
});

it('accrues exactly the full amount across a 28-day February', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 1000,
        'due_day' => null,
        'start_date' => '2026-02-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-02-28'));

    expect($row['accrued'])->toBe(1000.0)
        ->and($row['days_elapsed'])->toBe(28);
});

it('accrues exactly the full amount across a 29-day leap February', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 1000,
        'due_day' => null,
        'start_date' => '2028-02-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2028-02-29'));

    expect($row['accrued'])->toBe(1000.0)
        ->and($row['days_elapsed'])->toBe(29);
});

it('anchors the period on the start day-of-month, clamping month overflow', function () {
    // Start on the 31st: Feb has no 31st, so addMonthsNoOverflow clamps and the
    // March period re-snaps to the 31st (anchor-from-start, not incremental drift).
    $e = rentExpense($this->user, $this->category, [
        'amount' => 1000,
        'due_day' => null,
        'start_date' => '2026-01-31',
    ]);

    $feb = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-02-15'));
    // Period [2026-01-31, 2026-02-28): 28 days, Jan31..Feb15 = 16 days.
    expect($feb['accrued'])->toBe(571.43)
        ->and($feb['start_date'])->toBe('2026-01-31');

    $mar = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-03-15'));
    // Period [2026-02-28, 2026-03-31): 31 days, Feb28..Mar15 = 16 days.
    expect($mar['accrued'])->toBe(516.13)
        ->and($mar['start_date'])->toBe('2026-02-28');
});

it('counts only days since a mid-period start_date over the full period denominator', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 300,
        'due_day' => null,
        'start_date' => '2026-06-16',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-20'));

    // Period [2026-06-16, 2026-07-16): 30 days; Jun16..Jun20 = 5 days -> 300*5/30.
    expect($row['accrued'])->toBe(50.0)
        ->and($row['days_elapsed'])->toBe(5);
});

it('rolls into the next period at the exclusive period boundary', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 300,
        'due_day' => null,
        'start_date' => '2026-06-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-07-01'));

    // asOf == periodEnd of June -> belongs to July period [2026-07-01, 2026-08-01).
    expect($row['start_date'])->toBe('2026-07-01')
        ->and($row['days_elapsed'])->toBe(1)
        ->and($row['accrued'])->toBe(9.68); // 300 * 1/31
});

it('treats asOf before start_date as pending', function () {
    $e = rentExpense($this->user, $this->category, ['start_date' => '2026-07-01']);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-14'));

    expect($row['accrued'])->toBe(0.0)
        ->and($row['spent'])->toBe(0.0)
        ->and($row['status'])->toBe('pending')
        ->and($row['days_elapsed'])->toBe(0); // Carbon v3 signed diffInDays must not go negative
});

it('clamps days_elapsed to 0 even when asOf is far before start_date', function () {
    $e = rentExpense($this->user, $this->category, ['start_date' => '2026-12-01']);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-14'));

    expect($row['days_elapsed'])->toBe(0)
        ->and($row['status'])->toBe('pending');
});

// --- end_date handling (must not produce a negative denominator) ------------

it('caps the snapshot at a past end_date instead of corrupting the denominator', function () {
    // Still is_active but ended in February; asOf is months later.
    $e = rentExpense($this->user, $this->category, [
        'amount' => 1000,
        'due_day' => null,
        'start_date' => '2026-01-01',
        'end_date' => '2026-02-10',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-14'));

    // effectiveAsOf = 2026-02-10; period [2026-02-01, 2026-03-01): 28 days,
    // Feb1..Feb10 = 10 days -> 1000 * 10/28.
    expect($row['accrued'])->toBe(357.14)
        ->and($row['days_elapsed'])->toBe(10);
});

it('treats an end_date before start_date as pending', function () {
    $e = rentExpense($this->user, $this->category, [
        'start_date' => '2026-06-01',
        'end_date' => '2026-05-01',
    ]);

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-14'));

    expect($row['accrued'])->toBe(0.0)
        ->and($row['spent'])->toBe(0.0)
        ->and($row['status'])->toBe('pending');
});

// --- Spent matching (Bug 2: merchant match, not catch-all category) ---------

it('matches spent by merchant, ignoring unrelated transactions in a catch-all category', function () {
    $transfers = Category::factory()->create(['user_id' => $this->user->id, 'name' => 'Transfers']);
    $bell = FixedExpense::factory()->forCategory($transfers)->create([
        'name' => 'Compte A Payer Bell Canada',
        'amount' => 137.98,
        'frequency' => 'monthly',
        'due_day' => null,
        'start_date' => '2026-06-01',
        'is_active' => true,
    ]);

    // The real bill payment (merchant matches the expense name).
    paymentFor($this->user, $bell, 137.98, '2026-06-05', ['merchant_name' => 'COMPTE A PAYER BELL CANADA']);

    // Unrelated transfers sharing the same catch-all category — must NOT count.
    foreach ([[2000, '2026-06-03'], [1500, '2026-06-08'], [771, '2026-06-12']] as [$amt, $date]) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'category_id' => $transfers->id,
            'merchant_name' => 'VIREMENT INTERNET',
            'transaction_date' => $date,
            'amount' => -$amt,
            'is_excluded' => false,
        ]);
    }

    $row = app(AccrualCalculator::class)->computeOne($bell, CarbonImmutable::parse('2026-06-20'));

    // Spent is the single Bell payment only, not the $4271 of transfers.
    expect($row['spent'])->toBe(137.98)
        ->and($row['accrued'])->toBe(91.99) // 137.98 * 20/30
        ->and($row['status'])->toBe('ahead');
});

it('excludes an in-period payment dated after asOf from spent', function () {
    $e = rentExpense($this->user, $this->category, [
        'amount' => 300,
        'due_day' => 5,
        'start_date' => '2026-06-01',
    ]);

    // Payment lands on the 5th, but we ask as of the 3rd: not paid yet.
    paymentFor($this->user, $e, 300, '2026-06-05 10:00:00');

    $row = app(AccrualCalculator::class)->computeOne($e, CarbonImmutable::parse('2026-06-03'));

    expect($row['spent'])->toBe(0.0)
        ->and($row['accrued'])->toBe(30.0) // 300 * 3/30
        ->and($row['status'])->toBe('upcoming');
});

it('matches spent via match_pattern when the expense name differs from the bank merchant', function () {
    $gym = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Gym Membership', 'match_pattern' => 'GOODLIFE',
        'amount' => 50, 'frequency' => 'monthly', 'start_date' => '2026-06-01', 'is_active' => true,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $this->category->id,
        'merchant_name' => 'GOODLIFE FITNESS #221', 'description' => 'POS',
        'transaction_date' => '2026-06-10 10:00:00', 'amount' => -50, 'hash' => 'gym1', 'is_excluded' => false,
    ]);

    $row = app(AccrualCalculator::class)->computeOne($gym, CarbonImmutable::parse('2026-06-20'));

    expect($row['spent'])->toBe(50.0)
        ->and($row['matched_count'])->toBe(1)
        ->and($row['unmatched'])->toBeFalse();
});

it('falls back to the name as a substring when no match_pattern is set', function () {
    $hydro = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Hydro', 'match_pattern' => null,
        'amount' => 35, 'frequency' => 'monthly', 'start_date' => '2026-06-01', 'is_active' => true,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $this->category->id,
        'merchant_name' => 'FACTURE ELEC. HYDRO-QUEBEC', 'description' => 'BILL',
        'transaction_date' => '2026-06-10 10:00:00', 'amount' => -35, 'hash' => 'hyd1', 'is_excluded' => false,
    ]);

    $row = app(AccrualCalculator::class)->computeOne($hydro, CarbonImmutable::parse('2026-06-20'));

    expect($row['spent'])->toBe(35.0)
        ->and($row['matched_count'])->toBe(1);
});

it('flags an expense with no matching transactions as unmatched (not a false shortfall)', function () {
    $netflix = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Netflix', 'match_pattern' => null,
        // due_day pinned (factory default is random) so the period is already
        // active at the as-of date and the row isn't flagged 'upcoming'.
        'amount' => 17, 'frequency' => 'monthly', 'start_date' => '2026-06-01', 'due_day' => 1, 'is_active' => true,
    ]);
    // No matching transaction exists.

    $row = app(AccrualCalculator::class)->computeOne($netflix, CarbonImmutable::parse('2026-06-20'));

    expect($row['spent'])->toBe(0.0)
        ->and($row['matched_count'])->toBe(0)
        ->and($row['unmatched'])->toBeTrue();
});

// --- Cross-expense spend exclusivity (Bug: overlapping patterns must not double-count) ---

it('attributes an overlapping-pattern charge to the single longest-matching expense only', function () {
    $mobility = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Bell Mobility', 'match_pattern' => 'bell mobility',
        'amount' => 60, 'frequency' => 'monthly', 'due_day' => 1, 'start_date' => '2026-06-01', 'is_active' => true,
    ]);
    $internet = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Bell Internet', 'match_pattern' => 'bell',
        'amount' => 80, 'frequency' => 'monthly', 'due_day' => 1, 'start_date' => '2026-06-01', 'is_active' => true,
    ]);

    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $this->category->id,
        'merchant_name' => 'BELL MOBILITY', 'description' => 'POS',
        'transaction_date' => '2026-06-10 10:00:00', 'amount' => -60, 'hash' => 'bellmob1', 'is_excluded' => false,
    ]);

    $rows = app(AccrualCalculator::class)->snapshot(CarbonImmutable::parse('2026-06-20'))->keyBy('id');

    // The charge counts once, toward the longest ("most specific") match.
    expect($rows[$mobility->id]['spent'])->toBe(60.0)
        ->and($rows[$mobility->id]['matched_count'])->toBe(1)
        ->and($rows[$internet->id]['spent'])->toBe(0.0)
        ->and($rows[$internet->id]['matched_count'])->toBe(0)
        ->and($rows[$internet->id]['unmatched'])->toBeTrue();
});

it('still matches non-overlapping patterns independently across expenses', function () {
    $rent = rentExpense($this->user, $this->category, ['due_day' => 1]);
    $hydro = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Hydro', 'match_pattern' => 'hydro-quebec',
        'amount' => 35, 'frequency' => 'monthly', 'due_day' => 1, 'start_date' => '2026-06-01', 'is_active' => true,
    ]);

    paymentFor($this->user, $rent, 300, '2026-06-05 10:00:00');
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $this->category->id,
        'merchant_name' => 'FACTURE ELEC. HYDRO-QUEBEC', 'description' => 'BILL',
        'transaction_date' => '2026-06-08 10:00:00', 'amount' => -35, 'hash' => 'hyd2', 'is_excluded' => false,
    ]);

    $rows = app(AccrualCalculator::class)->snapshot(CarbonImmutable::parse('2026-06-20'))->keyBy('id');

    expect($rows[$rent->id]['spent'])->toBe(300.0)
        ->and($rows[$hydro->id]['spent'])->toBe(35.0);
});

it('does not count a payment whose row is an inter-account transfer', function () {
    $bill = FixedExpense::factory()->forCategory($this->category)->create([
        'name' => 'Bell', 'match_pattern' => null,
        'amount' => 100, 'frequency' => 'monthly', 'start_date' => '2026-06-01', 'is_active' => true,
    ]);
    $transfer = Transfer::factory()->create(['user_id' => $this->user->id]);
    Transaction::factory()->create([
        'user_id' => $this->user->id, 'category_id' => $this->category->id,
        'merchant_name' => 'BELL CANADA', 'description' => 'XFER',
        'transaction_date' => '2026-06-10 10:00:00', 'amount' => -100, 'hash' => 'bell1',
        'is_excluded' => false, 'transfer_id' => $transfer->id,
    ]);

    $row = app(AccrualCalculator::class)->computeOne($bill, CarbonImmutable::parse('2026-06-20'));

    expect($row['spent'])->toBe(0.0)->and($row['matched_count'])->toBe(0);
});
