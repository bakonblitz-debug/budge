<?php

namespace App\Http\Controllers;

use App\Models\BankAccount;
use App\Models\Setting;
use App\Models\UserProfile;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SettingsController extends Controller
{
    /**
     * Show the settings page.
     */
    public function index()
    {
        $user = auth()->user();

        return Inertia::render('Settings/Index', [
            'title' => 'Settings',
            'profile' => $user->profile ?? new UserProfile(),
            'bankAccounts' => BankAccount::orderBy('sort_order')->get(),
            'settings' => [
                'currency' => Setting::getValue('currency', 'CAD'),
                'fiscal_year_start' => Setting::getValue('fiscal_year_start', '01'),
            ],
        ]);
    }

    /**
     * Update the user's financial profile.
     */
    public function updateProfile(Request $request)
    {
        $validated = $request->validate([
            'gross_salary' => ['required', 'numeric', 'min:0'],
            'net_pay_per_period' => ['required', 'numeric', 'min:0'],
            'pay_frequency' => ['required', 'in:bi_weekly,weekly,monthly,semi_monthly'],
            'budget_start_date' => ['required', 'date'],
            'province' => ['required', 'string', 'max:50'],
        ]);

        $user = auth()->user();

        UserProfile::updateOrCreate(
            ['user_id' => $user->id],
            $validated,
        );

        return redirect()->back()->with([
            'message' => 'Profile updated successfully.',
            'type' => 'success',
        ]);
    }

    /**
     * Add or update a bank account.
     */
    public function storeBankAccount(Request $request)
    {
        $validated = $request->validate([
            'id' => ['nullable', 'exists:bank_accounts,id'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:chequing,savings,credit_card,line_of_credit,other'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'current_balance' => ['nullable', 'numeric'],
            'is_active' => ['boolean'],
        ]);

        if (! empty($validated['id'])) {
            $account = BankAccount::findOrFail($validated['id']);
            $account->update($validated);
            $message = "Bank account '{$account->name}' updated.";
        } else {
            unset($validated['id']);
            $account = BankAccount::create($validated);
            $message = "Bank account '{$account->name}' added.";
        }

        return redirect()->back()->with([
            'message' => $message,
            'type' => 'success',
        ]);
    }

    /**
     * Delete a bank account.
     */
    public function destroyBankAccount(BankAccount $bankAccount)
    {
        $name = $bankAccount->name;
        $bankAccount->delete();

        return redirect()->back()->with([
            'message' => "Bank account '{$name}' deleted.",
            'type' => 'success',
        ]);
    }

    /**
     * Update app-level settings.
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'currency' => ['string', 'size:3'],
            'fiscal_year_start' => ['string', 'max:2'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::setValue($key, $value);
        }

        return redirect()->back()->with([
            'message' => 'Settings saved.',
            'type' => 'success',
        ]);
    }
}
