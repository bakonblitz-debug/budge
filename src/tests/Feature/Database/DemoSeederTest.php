<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\CategoryRule;
use App\Models\ImportBatch;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;

beforeEach(function () {
    // Freeze time so the seeder's now()-bounded generation is identical across
    // runs (it picks a random NUMBER of purchases per month via faker — which is
    // seeded — but the current partial month is bounded by now(); freezing keeps
    // the idempotency re-seed deterministic instead of racing the wall clock).
    Carbon::setTestNow('2026-06-15 12:00:00');
    $this->seed(DemoSeeder::class);
    $this->demo = User::where('email', 'demo@budgetapp.local')->firstOrFail();
});

afterEach(function () {
    Carbon::setTestNow(null);
});

it('creates a verified demo user', function () {
    expect($this->demo->name)->toBe('Demo User')
        ->and($this->demo->email_verified_at)->not->toBeNull()
        ->and($this->demo->onboarding_completed_at)->not->toBeNull();
});

it('mirrors the real category tree and rules', function () {
    expect(Category::count())->toBe(23)
        ->and(Category::whereNull('parent_id')->count())->toBe(11)
        ->and(Category::whereNotNull('parent_id')->count())->toBe(12)
        ->and(CategoryRule::count())->toBe(65);
});

it('creates two years of import batches for both accounts', function () {
    expect(ImportBatch::count())->toBe(48)
        ->and(ImportBatch::where('bank_format', 'nbc_bank_pdf')->count())->toBe(24)
        ->and(ImportBatch::where('bank_format', 'nbc_credit_card_pdf')->count())->toBe(24)
        ->and(ImportBatch::where('status', 'completed')->count())->toBe(48);
});

it('records each batch import_count equal to its transactions', function () {
    ImportBatch::all()->each(function (ImportBatch $batch) {
        $actual = Transaction::where('import_batch_id', $batch->id)->count();
        expect($batch->imported_count)->toBe($actual)
            ->and($batch->total_rows)->toBe($actual);
    });
});

it('keeps the chequing running balance internally consistent', function () {
    $chequing = BankAccount::where('type', 'chequing')->firstOrFail();

    $transactions = Transaction::where('bank_account_id', $chequing->id)
        ->orderBy('transaction_date')
        ->orderBy('id')
        ->get();

    expect($transactions)->not->toBeEmpty();

    // Each balance_after must equal the prior balance plus the row amount.
    $previous = null;
    foreach ($transactions as $transaction) {
        if ($previous !== null) {
            $expected = (float) $previous->balance_after + (float) $transaction->amount;
            expect((float) $transaction->balance_after)->toBeWithin($expected);
        }
        $previous = $transaction;
    }

    // The account cache equals the final running balance.
    expect((float) $chequing->current_balance)->toBeWithin((float) $transactions->last()->balance_after);
});

it('reconciles the credit card balance with its transactions', function () {
    $card = BankAccount::where('type', 'credit_card')->firstOrFail();

    $sum = (float) Transaction::where('bank_account_id', $card->id)->sum('amount');

    expect((float) $card->current_balance)->toBeWithin($sum)
        // Card statements carry no running balance.
        ->and(Transaction::where('bank_account_id', $card->id)->whereNotNull('balance_after')->count())->toBe(0);
});

it('categorizes known merchants through the rule engine', function () {
    $categoryFor = fn (string $name) => Category::where('name', $name)->value('id');

    $cases = [
        ['DEPOT SALAIRE ACME CORP', 'Income'],
        ['WMT SUPRCTR', 'Groceries'],
        ['SHELL', 'Gas'],
        ['MCDONALD', 'Restaurants'],
        ['HYDRO-QUEBEC', 'Utilities'],
        ['MOBILE PAIEM. MASTERCARD', 'Transfers'],
    ];

    foreach ($cases as [$needle, $categoryName]) {
        $transaction = Transaction::where('description', 'like', "%{$needle}%")->first();
        expect($transaction)->not->toBeNull("expected a transaction matching {$needle}");
        expect($transaction->category_id)->toBe($categoryFor($categoryName));
    }
});

it('populates a match-key hash on every row and no future-dated rows', function () {
    $total = Transaction::count();

    // Hashes are intentionally NON-unique now (the recipe is a day-granular
    // match key, so legitimate same-day repeats collide). Just assert every row
    // carries a 64-char SHA-256 key and nothing is future-dated.
    $badHashes = Transaction::pluck('hash')
        ->filter(fn ($hash) => $hash === null || strlen($hash) !== 64)
        ->count();

    expect($total)->toBeGreaterThan(500)
        ->and($badHashes)->toBe(0)
        ->and(Transaction::where('transaction_date', '>', now())->count())->toBe(0);
});

it('grows the chequing balance over the two years', function () {
    $chequing = BankAccount::where('type', 'chequing')->firstOrFail();

    expect((float) $chequing->current_balance)->toBeGreaterThan(2150.00);
});

it('is idempotent when run twice', function () {
    $before = [
        'users' => User::count(),
        'transactions' => Transaction::count(),
        'batches' => ImportBatch::count(),
        'categories' => Category::count(),
    ];

    $this->seed(DemoSeeder::class);

    expect(User::count())->toBe($before['users'])
        ->and(Transaction::count())->toBe($before['transactions'])
        ->and(ImportBatch::count())->toBe($before['batches'])
        ->and(Category::count())->toBe($before['categories']);
});
