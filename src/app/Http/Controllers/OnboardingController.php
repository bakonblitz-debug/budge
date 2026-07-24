<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class OnboardingController extends Controller
{
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Housing', 'icon' => 'mdi-home', 'color' => '#5E6BC4', 'kind' => 'need', 'children' => ['Rent', 'Utilities', 'Internet']],
        ['name' => 'Food', 'icon' => 'mdi-food', 'color' => '#FB8C00', 'kind' => null, 'children' => ['Groceries', 'Restaurants']],
        ['name' => 'Transport', 'icon' => 'mdi-car', 'color' => '#43A047', 'kind' => 'need', 'children' => ['Gas', 'Public Transit']],
        ['name' => 'Subscriptions', 'icon' => 'mdi-credit-card-outline', 'color' => '#8E24AA', 'kind' => 'want', 'children' => []],
        ['name' => 'Health', 'icon' => 'mdi-medical-bag', 'color' => '#E53935', 'kind' => 'need', 'children' => []],
        ['name' => 'Entertainment', 'icon' => 'mdi-controller', 'color' => '#00ACC1', 'kind' => 'want', 'children' => []],
        ['name' => 'Shopping', 'icon' => 'mdi-cart', 'color' => '#FDD835', 'kind' => 'want', 'children' => []],
        ['name' => 'Income', 'icon' => 'mdi-cash-plus', 'color' => '#2E7D32', 'kind' => 'income', 'children' => []],
        ['name' => 'Transfers', 'icon' => 'mdi-bank-transfer', 'color' => '#546E7A', 'kind' => 'excluded', 'children' => []],
        ['name' => 'Other', 'icon' => 'mdi-dots-horizontal', 'color' => '#757575', 'kind' => null, 'children' => []],
    ];

    public function index()
    {
        if (auth()->user()->hasCompletedOnboarding()) {
            return redirect('/');
        }

        return Inertia::render('Onboarding/Index', [
            'title' => 'Welcome',
            'defaultCategories' => self::DEFAULT_CATEGORIES,
            'provinces' => ['QC', 'ON', 'BC', 'AB', 'MB', 'SK', 'NS', 'NB', 'NL', 'PE', 'YT', 'NT', 'NU'],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'profile.gross_salary' => ['required', 'numeric', 'min:0'],
            'profile.net_pay_per_period' => ['required', 'numeric', 'min:0'],
            'profile.pay_frequency' => ['required', 'in:bi_weekly,weekly,monthly,semi_monthly'],
            'profile.budget_start_date' => ['required', 'date'],
            'profile.province' => ['required', 'string', 'max:50'],

            'bank_account.name' => ['required', 'string', 'max:255'],
            'bank_account.type' => ['required', 'in:chequing,savings,credit_card,line_of_credit,other'],
            'bank_account.current_balance' => ['nullable', 'numeric'],
            'bank_account.credit_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = auth()->user();

        DB::transaction(function () use ($user, $validated) {
            UserProfile::updateOrCreate(
                ['user_id' => $user->id],
                $validated['profile'],
            );

            BankAccount::create($validated['bank_account']);

            $this->seedDefaultCategories();

            $user->forceFill(['onboarding_completed_at' => now()])->save();
        });

        return redirect('/')->with([
            'message' => 'Welcome to BudgetApp! Your account is ready.',
            'type' => 'success',
        ]);
    }

    private function seedDefaultCategories(): void
    {
        foreach (self::DEFAULT_CATEGORIES as $index => $cat) {
            $parent = Category::create([
                'name' => $cat['name'],
                'icon' => $cat['icon'],
                'color' => $cat['color'],
                'sort_order' => $index * 10,
                'kind' => $cat['kind'] ?? null,
            ]);

            foreach ($cat['children'] as $childIndex => $childName) {
                Category::create([
                    'parent_id' => $parent->id,
                    'name' => $childName,
                    'icon' => $cat['icon'],
                    'color' => $cat['color'],
                    'sort_order' => $childIndex,
                ]);
            }
        }
    }
}
