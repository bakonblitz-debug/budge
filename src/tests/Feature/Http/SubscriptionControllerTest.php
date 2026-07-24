<?php

use App\Models\Category;
use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->user = User::factory()->create(['onboarding_completed_at' => now()]);
    $this->actingAs($this->user);
});

it('rescans and builds recurring series via the endpoint', function () {
    $now = Carbon::now();
    foreach ([90, 60, 30, 0] as $daysAgo) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'NETFLIX',
            'merchant_name' => 'NETFLIX',
            'amount' => -16.49,
            'transaction_date' => $now->copy()->subDays($daysAgo)->toDateTimeString(),
            'hash' => bin2hex(random_bytes(16)),
        ]);
    }

    $this->post('/subscriptions/detect')->assertRedirect();

    expect(RecurringSeries::where('merchant_key', 'netflix')->where('status', 'active')->exists())->toBeTrue();
});

it('renders the subscriptions page with the active total', function () {
    RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'NETFLIX',
        'cadence' => 'monthly',
        'expected_amount' => 16.49,
        'status' => 'active',
    ]);

    $this->get('/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Subscriptions/Index')
            ->where('totals.active_count', 1)
            ->has('series', 1)
            ->has('candidates')
            ->has('dismissed')
        );
});

it('surfaces candidates and the dismissed list on the index', function () {
    $now = Carbon::now();
    foreach ([30, 0] as $daysAgo) {
        Transaction::factory()->create([
            'user_id' => $this->user->id,
            'description' => 'MAYBE SUB',
            'merchant_name' => 'MAYBE SUB',
            'amount' => -7.99,
            'transaction_date' => $now->copy()->subDays($daysAgo)->toDateTimeString(),
            'hash' => bin2hex(random_bytes(16)),
        ]);
    }
    RecurringSeries::factory()->dismissed()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'old false positive',
    ]);

    $this->get('/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('candidates', 1)
            ->has('dismissed', 1)
        );
});

it('dismisses an active series', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'netflix',
        'status' => 'active',
    ]);

    $this->patch("/subscriptions/{$series->id}/dismiss")->assertRedirect();

    expect($series->fresh()->status)->toBe('dismissed');
});

it('restores a dismissed series', function () {
    $series = RecurringSeries::factory()->dismissed()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'netflix',
    ]);

    $this->patch("/subscriptions/{$series->id}/restore")->assertRedirect();

    expect($series->fresh()->status)->toBe('active');
});

it('manually adds a subscription from a candidate', function () {
    $this->post('/subscriptions', [
        'merchant_key' => 'my gym',
        'cadence' => 'monthly',
        'expected_amount' => 45.00,
    ])->assertRedirect();

    $series = RecurringSeries::where('merchant_key', 'my gym')->first();
    expect($series)->not->toBeNull()
        ->and($series->is_manual)->toBeTrue()
        ->and($series->status)->toBe('active')
        ->and($series->cadence)->toBe('monthly')
        ->and((float) $series->expected_amount)->toBe(45.00);
});

it('normalizes the merchant key when manually adding a subscription', function () {
    $this->post('/subscriptions', [
        'merchant_key' => '  My GYM   Membership ',
        'cadence' => 'monthly',
        'expected_amount' => 45.00,
    ])->assertRedirect();

    expect(RecurringSeries::where('merchant_key', 'my gym membership')->exists())->toBeTrue();
});

it('validates the manual add payload', function () {
    $this->post('/subscriptions', ['merchant_key' => '', 'cadence' => 'fortnightly'])
        ->assertSessionHasErrors(['merchant_key', 'cadence']);
});

it('converts a series into a fixed expense with the right details', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'fido mobile',
        'category_id' => $category->id,
        'cadence' => 'monthly',
        'expected_amount' => 65.00,
        'last_seen_at' => '2026-05-12',
        'next_expected_at' => '2026-06-12',
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertRedirect();

    $expense = FixedExpense::where('name', 'Fido Mobile')->first();
    expect($expense)->not->toBeNull()
        ->and($expense->category_id)->toBe($category->id)
        ->and($expense->frequency)->toBe('monthly')
        ->and((float) $expense->amount)->toBe(65.00)
        ->and($expense->due_day)->toBe(12)
        ->and(optional($expense->start_date)->toDateString())->toBe('2026-05-12')
        ->and($expense->is_active)->toBeTrue()
        ->and($expense->sort_order)->toBe(0)
        ->and($expense->notes)->toBe('Converted from detected subscription');
});

it('refuses to convert a series that has no category', function () {
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'rent payment',
        'category_id' => null,
        'cadence' => 'monthly',
        'expected_amount' => 1675.00,
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertRedirect();

    expect(FixedExpense::count())->toBe(0)
        ->and($series->fresh()->status)->toBe('active');
});

it('maps each cadence to the right frequency and amount', function (string $cadence, string $frequency, float $expected, float $amount) {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => "merchant {$cadence}",
        'category_id' => $category->id,
        'cadence' => $cadence,
        'expected_amount' => $expected,
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertRedirect();

    $expense = FixedExpense::where('name', ucwords("merchant {$cadence}"))->first();
    expect($expense)->not->toBeNull()
        ->and($expense->frequency)->toBe($frequency)
        ->and((float) $expense->amount)->toBe($amount);
})->with([
    'weekly' => ['weekly', 'weekly', 10.00, 10.00],
    'bi_weekly' => ['bi_weekly', 'bi_weekly', 10.00, 10.00],
    'monthly' => ['monthly', 'monthly', 30.00, 30.00],
    'quarterly' => ['quarterly', 'quarterly', 90.00, 90.00],
    'yearly' => ['yearly', 'yearly', 120.00, 120.00],
    'bi_monthly' => ['bi_monthly', 'monthly', 50.00, 25.00],
    'semi_annual' => ['semi_annual', 'monthly', 60.00, 10.00],
]);

it('uses last_seen_at day-of-month when next_expected_at is null, clamped to 28', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'hydro quebec',
        'category_id' => $category->id,
        'cadence' => 'monthly',
        'expected_amount' => 90.00,
        'next_expected_at' => null,
        'last_seen_at' => '2026-05-31',
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertRedirect();

    $expense = FixedExpense::where('name', 'Hydro Quebec')->first();
    expect($expense->due_day)->toBe(28)
        ->and(optional($expense->start_date)->toDateString())->toBe('2026-05-31');
});

it('marks the series converted and drops it from the active index list', function () {
    $category = Category::factory()->create(['user_id' => $this->user->id]);
    $series = RecurringSeries::factory()->create([
        'user_id' => $this->user->id,
        'merchant_key' => 'fido mobile',
        'category_id' => $category->id,
        'cadence' => 'monthly',
        'expected_amount' => 65.00,
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertRedirect();

    expect($series->fresh()->status)->toBe('converted');

    $this->get('/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('totals.active_count', 0)
            ->has('series', 0)
        );
});

it('cannot convert another user\'s series', function () {
    $other = User::factory()->create();
    $series = RecurringSeries::factory()->create([
        'user_id' => $other->id,
        'merchant_key' => 'netflix',
        'status' => 'active',
    ]);

    $this->post("/subscriptions/{$series->id}/convert")->assertNotFound();
});

it('cannot dismiss another user\'s series', function () {
    $other = User::factory()->create();
    $series = RecurringSeries::factory()->create([
        'user_id' => $other->id,
        'merchant_key' => 'netflix',
        'status' => 'active',
    ]);

    $this->patch("/subscriptions/{$series->id}/dismiss")->assertNotFound();
});

it('guards state-changing subscription routes with hmac', function () {
    $routes = collect(Route::getRoutes())->filter(
        fn ($r) => str_starts_with($r->uri(), 'subscriptions') && ! in_array('GET', $r->methods(), true),
    );

    expect($routes)->not->toBeEmpty();
    $routes->each(fn ($r) => expect($r->gatherMiddleware())->toContain('hmac'));
});
