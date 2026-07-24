<?php

use App\Models\User;
use Database\Seeders\DemoSeeder;

/**
 * Every page must render against the realistic 24-month demo dataset. This
 * catches server-side render bugs and exceptions on a normal, data-heavy load.
 *
 * NOTE: this asserts the controller/Inertia response, not the browser render —
 * a Vue page can still throw client-side while the server returns 200 (see the
 * FixedExpenses `formatDate` regression). A Pest browser smoke test is the
 * complement for catching that class.
 */
it('renders every page over the demo dataset', function (string $uri) {
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::where('email', 'demo@budgetapp.local')->firstOrFail());

    $this->get($uri)->assertOk();
})->with([
    '/', '/transactions', '/transactions/import', '/categorize', '/categories',
    '/merchants', '/income', '/fixed-expenses', '/budgets', '/subscriptions',
    '/forecast', '/net-worth', '/envelope-pools', '/accrual', '/insights',
    '/milestones', '/audit', '/settings',
]);
