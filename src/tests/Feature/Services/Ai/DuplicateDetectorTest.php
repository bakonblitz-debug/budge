<?php

use App\Models\BankAccount;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ai\DuplicateDetector;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.anthropic.key', 'test-key');
    config()->set('services.anthropic.models.classify', 'claude-haiku-4-5');

    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->account = BankAccount::factory()->create(['user_id' => $this->user->id]);
});

function fakeVerdicts(array $verdicts): void
{
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [[
                'type' => 'text',
                'text' => json_encode(['verdicts' => $verdicts]),
            ]],
        ], 200),
    ]);
}

function makeTxn(int $userId, int $accountId, string $date, string $description, float $amount): Transaction
{
    return Transaction::factory()->create([
        'user_id' => $userId,
        'bank_account_id' => $accountId,
        'transaction_date' => $date,
        'description' => $description,
        'amount' => $amount,
        'category_id' => null,
        'hash' => Transaction::generateHash($date, $description, $amount),
        'is_excluded' => false,
    ]);
}

it('pairs same-account same-amount near-date transactions as candidates', function () {
    $a = makeTxn($this->user->id, $this->account->id, '2026-01-10 00:00:00', 'STARBUCKS PENDING', -5.25);
    $b = makeTxn($this->user->id, $this->account->id, '2026-01-11 00:00:00', 'STARBUCKS #123', -5.25);
    // Out of window / different amount — should NOT pair
    makeTxn($this->user->id, $this->account->id, '2026-02-20 00:00:00', 'STARBUCKS #123', -5.25);
    makeTxn($this->user->id, $this->account->id, '2026-01-11 00:00:00', 'OTHER', -9.99);

    $pairs = app(DuplicateDetector::class)->candidatePairs(dayWindow: 3);

    expect($pairs)->toHaveCount(1);
    expect(collect($pairs[0])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('never pairs transactions a recurring cadence apart, even with a wide requested window', function () {
    // Two paycheques, same amount, 14 days apart (bi-weekly / "2 pays a month").
    makeTxn($this->user->id, $this->account->id, '2026-01-01 00:00:00', 'PAYROLL DEPOSIT', 2200.00);
    makeTxn($this->user->id, $this->account->id, '2026-01-15 00:00:00', 'PAYROLL DEPOSIT', 2200.00);

    // A semi-monthly bill, same amount, ~15 days apart.
    makeTxn($this->user->id, $this->account->id, '2026-01-03 00:00:00', 'INSURANCE', -85.00);
    makeTxn($this->user->id, $this->account->id, '2026-01-18 00:00:00', 'INSURANCE', -85.00);

    // Even if dedup is run with a wide window, a recurring cadence (≥1 week apart)
    // must never become a candidate duplicate.
    expect(app(DuplicateDetector::class)->candidatePairs(dayWindow: 30))->toBe([]);
});

it('still pairs a genuine duplicate that posts a few days late', function () {
    $a = makeTxn($this->user->id, $this->account->id, '2026-01-10 00:00:00', 'HYDRO QUEBEC PENDING', -120.00);
    $b = makeTxn($this->user->id, $this->account->id, '2026-01-14 00:00:00', 'HYDRO QUEBEC', -120.00); // 4 days later

    $pairs = app(DuplicateDetector::class)->candidatePairs(dayWindow: 30);

    expect($pairs)->toHaveCount(1)
        ->and(collect($pairs[0])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$a->id, $b->id])->sort()->values()->all());
});

it('confirms duplicates the model accepts above the threshold', function () {
    $a = makeTxn($this->user->id, $this->account->id, '2026-01-10 00:00:00', 'STARBUCKS PENDING', -5.25);
    $b = makeTxn($this->user->id, $this->account->id, '2026-01-11 00:00:00', 'STARBUCKS #123', -5.25);

    fakeVerdicts([['index' => 0, 'is_duplicate' => true, 'confidence' => 0.95]]);

    $dupes = app(DuplicateDetector::class)->findDuplicates(dayWindow: 3, threshold: 0.8);

    expect($dupes)->toHaveCount(1);
    // higher id is the duplicate to drop
    expect($dupes[0]['keep']->id)->toBe(min($a->id, $b->id));
    expect($dupes[0]['duplicate']->id)->toBe(max($a->id, $b->id));
});

it('ignores pairs the model rejects', function () {
    makeTxn($this->user->id, $this->account->id, '2026-01-10 00:00:00', 'COFFEE', -5.25);
    makeTxn($this->user->id, $this->account->id, '2026-01-11 00:00:00', 'COFFEE', -5.25);

    fakeVerdicts([['index' => 0, 'is_duplicate' => false, 'confidence' => 0.9]]);

    expect(app(DuplicateDetector::class)->findDuplicates(dayWindow: 3))->toBe([]);
});
