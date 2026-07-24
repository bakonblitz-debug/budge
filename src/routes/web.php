<?php

use App\Http\Controllers\AccrualController;
use App\Http\Controllers\AssetController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategorizationController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EnvelopePoolController;
use App\Http\Controllers\FixedExpenseController;
use App\Http\Controllers\ForecastController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\IncomeController;
use App\Http\Controllers\InsightsController;
use App\Http\Controllers\LiabilityController;
use App\Http\Controllers\MerchantAliasController;
use App\Http\Controllers\MilestoneController;
use App\Http\Controllers\NetWorthController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransferController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Guest / authentication routes (no auth required)
|--------------------------------------------------------------------------
| Email + password sign-in. Each additional sign-in method funnels through
| the same Authenticator service, so adding one is a new controller here, not
| a change to the protected app below.
*/
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store']);

    // ── Google SSO (future) ──────────────────────────────────────────────
    // Needs `composer require laravel/socialite`, Google OAuth credentials,
    // and a nullable `password` + `google_id` on users. Both endpoints resolve
    // or provision the user, call Auth::login(), then Authenticator@completeLogin.
    //   Route::get('/auth/google/redirect', [OAuthController::class, 'redirect'])->name('oauth.google.redirect');
    //   Route::get('/auth/google/callback', [OAuthController::class, 'callback'])->name('oauth.google.callback');

    // ── Two-factor challenge (future) ────────────────────────────────────
    // Shown after a correct password when the user has 2FA enabled; verifies
    // the TOTP/recovery code, then finishes via Authenticator@completeLogin.
    //   Route::get('/two-factor/challenge', [TwoFactorChallengeController::class, 'create'])->name('two-factor.challenge');
    //   Route::post('/two-factor/challenge', [TwoFactorChallengeController::class, 'store']);
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Authenticated application
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index']);

    // ── Onboarding ──
    Route::controller(OnboardingController::class)->prefix('onboarding')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
    });

    // ── Transactions ──
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::patch('/transactions/{transaction}', [TransactionController::class, 'update'])->middleware('hmac');

    // ── Transfers (link/unlink inter-account moves) ──
    Route::controller(TransferController::class)->prefix('transfers')->group(function () {
        Route::post('/', 'store')->middleware('hmac');
        Route::post('/detect', 'detect')->middleware('hmac');
        Route::delete('/{transfer}', 'destroy')->middleware('hmac');
    });

    // ── CSV Import ──
    Route::controller(ImportController::class)->prefix('transactions/import')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::get('/{batch}', 'show');
    });

    // ── Settings ──
    Route::controller(SettingsController::class)->prefix('settings')->group(function () {
        Route::get('/', 'index');
        Route::post('/profile', 'updateProfile')->middleware('hmac');
        Route::post('/bank-accounts', 'storeBankAccount')->middleware('hmac');
        Route::delete('/bank-accounts/{bankAccount}', 'destroyBankAccount')->middleware('hmac');
        Route::post('/app', 'updateSettings')->middleware('hmac');
    });

    Route::controller(ProfileController::class)->prefix('profile')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'updateProfile')->middleware('hmac');
        Route::post('/password', 'updatePassword')->middleware('hmac');
        Route::post('/avatar', 'updateAvatar')->middleware('hmac');
        Route::delete('/avatar', 'deleteAvatar')->middleware('hmac');
        Route::post('/preferences', 'updatePreferences')->middleware('hmac');
    });

    // ── Categorize (training UI) ──
    Route::controller(CategorizationController::class)->prefix('categorize')->group(function () {
        Route::get('/', 'index');
        Route::post('/apply', 'apply')->middleware('hmac');
        Route::post('/unset', 'unset')->middleware('hmac');
    });

    // ── Merchant aliases (clean display names) ──
    Route::controller(MerchantAliasController::class)->prefix('merchants')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{merchant}', 'update')->middleware('hmac');
        Route::delete('/{merchant}', 'destroy')->middleware('hmac');
    });

    // ── Cash-flow forecast ──
    Route::get('/forecast', [ForecastController::class, 'index']);

    // ── Net worth (assets, liabilities, snapshots) ──
    Route::controller(NetWorthController::class)->prefix('net-worth')->group(function () {
        Route::get('/', 'index');
        Route::post('/snapshot', 'snapshot')->middleware('hmac');
    });
    Route::controller(AssetController::class)->prefix('assets')->group(function () {
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{asset}', 'update')->middleware('hmac');
        Route::delete('/{asset}', 'destroy')->middleware('hmac');
    });
    Route::controller(LiabilityController::class)->prefix('liabilities')->group(function () {
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{liability}', 'update')->middleware('hmac');
        Route::delete('/{liability}', 'destroy')->middleware('hmac');
    });

    // ── Subscriptions (recurring spend) ──
    Route::controller(SubscriptionController::class)->prefix('subscriptions')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::post('/detect', 'detect')->middleware('hmac');
        Route::patch('/{series}/dismiss', 'dismiss')->middleware('hmac');
        Route::patch('/{series}/restore', 'restore')->middleware('hmac');
        Route::post('/{series}/convert', 'convert')->middleware('hmac');
    });

    // ── Categories ──
    Route::controller(CategoryController::class)->prefix('categories')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{category}', 'update')->middleware('hmac');
        Route::delete('/{category}', 'destroy')->middleware('hmac');
        Route::post('/{category}/rules', 'storeRule')->middleware('hmac');
        Route::delete('/{category}/rules/{rule}', 'destroyRule')->middleware('hmac');
    });

    // ── Placeholder routes — will be replaced with controllers ──
    Route::controller(IncomeController::class)->prefix('income')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{income}', 'update')->middleware('hmac');
        Route::delete('/{income}', 'destroy')->middleware('hmac');
    });
    Route::controller(FixedExpenseController::class)->prefix('fixed-expenses')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{fixedExpense}', 'update')->middleware('hmac');
        Route::delete('/{fixedExpense}', 'destroy')->middleware('hmac');
    });
    Route::controller(BudgetController::class)->prefix('budgets')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{budget}', 'update')->middleware('hmac');
        Route::delete('/{budget}', 'destroy')->middleware('hmac');
    });
    Route::controller(EnvelopePoolController::class)->prefix('envelope-pools')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{envelopePool}', 'update')->middleware('hmac');
        Route::delete('/{envelopePool}', 'destroy')->middleware('hmac');
    });
    Route::get('/accrual', [AccrualController::class, 'index']);
    Route::get('/insights', [InsightsController::class, 'index']);
    Route::permanentRedirect('/statistics', '/insights');
    Route::controller(MilestoneController::class)->prefix('milestones')->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store')->middleware('hmac');
        Route::patch('/{milestone}', 'update')->middleware('hmac');
        Route::delete('/{milestone}', 'destroy')->middleware('hmac');
    });
    Route::get('/audit', fn () => Inertia::render('Placeholder', ['title' => 'Audit Log', 'icon' => 'mdi-history']));
});
