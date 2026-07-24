<?php

namespace App\Http\Controllers;

use App\Models\FixedExpense;
use App\Models\RecurringSeries;
use App\Services\RecurringDetector;
use App\Support\MerchantKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class SubscriptionController extends Controller
{
    /** Cadences the user may pick when manually adding a subscription. */
    private const CADENCES = ['weekly', 'bi_weekly', 'monthly', 'bi_monthly', 'quarterly', 'semi_annual', 'yearly'];

    public function __construct(private readonly RecurringDetector $detector) {}

    public function index(): Response
    {
        $series = RecurringSeries::query()
            ->with('category:id,name,icon,color')
            ->whereIn('status', ['active', 'ended'])
            ->orderByRaw("status = 'active' DESC")
            ->orderBy('next_expected_at')
            ->get();

        $dismissed = RecurringSeries::query()
            ->with('category:id,name,icon,color')
            ->where('status', 'dismissed')
            ->orderBy('merchant_key')
            ->get();

        $active = $series->where('status', 'active');

        return Inertia::render('Subscriptions/Index', [
            'title' => 'Subscriptions',
            'series' => $series->values(),
            'dismissed' => $dismissed->values(),
            'candidates' => $this->detector->candidates(),
            'totals' => [
                'active_count' => $active->count(),
                'monthly_estimate' => round($active->sum(fn (RecurringSeries $s) => $this->monthlyCost($s)), 2),
            ],
        ]);
    }

    /** Manually add a subscription (e.g. promoted from a candidate). */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'merchant_key' => ['required', 'string', 'max:255'],
            'cadence' => ['required', 'string', 'in:'.implode(',', self::CADENCES)],
            'expected_amount' => ['nullable', 'numeric', 'min:0'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $amount = round((float) ($data['expected_amount'] ?? 0), 2);

        RecurringSeries::create([
            // Normalized so this key aligns with RecurringDetector's grouping
            // key — otherwise a manual series never matches its own real
            // transactions and can never reconcile on a rescan.
            'merchant_key' => MerchantKey::normalize($data['merchant_key']),
            'cadence' => $data['cadence'],
            'category_id' => $data['category_id'] ?? null,
            'expected_amount' => $amount,
            'last_amount' => $amount,
            'amount_increased' => false,
            'occurrences' => 0,
            'last_seen_at' => now()->toDateString(),
            'next_expected_at' => now()->toDateString(),
            'status' => 'active',
            'is_manual' => true,
        ]);

        return back()->with(['message' => 'Subscription added.', 'type' => 'success']);
    }

    /** Hide a false-positive series; survives rescans. */
    public function dismiss(RecurringSeries $series): RedirectResponse
    {
        $series->update(['status' => 'dismissed']);

        return back()->with(['message' => 'Subscription dismissed.', 'type' => 'success']);
    }

    /** Bring a dismissed series back as active. */
    public function restore(RecurringSeries $series): RedirectResponse
    {
        $series->update(['status' => 'active']);

        return back()->with(['message' => 'Subscription restored.', 'type' => 'success']);
    }

    /**
     * Promote a detected recurring series into a tracked FixedExpense, then
     * mark the series converted so it leaves the Subscriptions list and isn't
     * re-detected on a rescan.
     */
    public function convert(RecurringSeries $series): RedirectResponse
    {
        // Fixed expenses require a category; a series without one can't be
        // promoted as-is. Ask the user to categorise it first.
        if ($series->category_id === null) {
            return back()->with([
                'message' => "Give '{$series->merchant_key}' a category before converting it to a fixed expense.",
                'type' => 'error',
            ]);
        }

        [$frequency, $amount] = $this->fixedExpenseCadence($series);

        $anchor = $series->next_expected_at ?? $series->last_seen_at;
        $dueDay = $anchor ? max(1, min(28, (int) $anchor->day)) : null;

        FixedExpense::create([
            'category_id' => $series->category_id,
            'name' => ucwords($series->merchant_key),
            'amount' => $amount,
            'frequency' => $frequency,
            'due_day' => $dueDay,
            'start_date' => $series->last_seen_at ?? Carbon::today(),
            'is_active' => true,
            'sort_order' => 0,
            'notes' => 'Converted from detected subscription',
        ]);

        $series->update(['status' => 'converted']);

        return back()->with([
            'message' => "'{$series->merchant_key}' now lives in Fixed Expenses.",
            'type' => 'success',
        ]);
    }

    /**
     * Map a series' cadence onto a FixedExpense (frequency, amount). Cadences
     * Fixed Expenses doesn't support (every 2 / 6 months) collapse to a
     * monthly-equivalent amount.
     *
     * @return array{0: string, 1: float}
     */
    private function fixedExpenseCadence(RecurringSeries $series): array
    {
        $amount = (float) $series->expected_amount;

        return match ($series->cadence) {
            'bi_monthly' => ['monthly', round($amount / 2, 2)],
            'semi_annual' => ['monthly', round($amount / 6, 2)],
            default => [$series->cadence, round($amount, 2)],
        };
    }

    /** Re-scan transactions and rebuild the recurring series. */
    public function detect()
    {
        $count = $this->detector->detect();

        return back()->with([
            'message' => "Found {$count} recurring ".($count === 1 ? 'series' : 'series').'.',
            'type' => 'success',
        ]);
    }

    /** Normalize a series' typical charge to a monthly figure. */
    private function monthlyCost(RecurringSeries $s): float
    {
        $amount = (float) $s->expected_amount;

        return match ($s->cadence) {
            'weekly' => $amount * 52 / 12,
            'bi_weekly' => $amount * 26 / 12,
            'bi_monthly' => $amount / 2,
            'quarterly' => $amount / 3,
            'semi_annual' => $amount / 6,
            'yearly' => $amount / 12,
            default => $amount, // monthly
        };
    }
}
