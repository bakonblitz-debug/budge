<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Liability;
use App\Models\NetWorthSnapshot;
use App\Services\NetWorthService;
use Inertia\Inertia;

class NetWorthController extends Controller
{
    public function __construct(private readonly NetWorthService $netWorth) {}

    public function index()
    {
        return Inertia::render('NetWorth/Index', [
            'title' => 'Net worth',
            'current' => $this->netWorth->current(),
            'assets' => Asset::query()->orderByDesc('current_value')->get(),
            'liabilities' => Liability::query()->orderByDesc('balance')->get(),
            'history' => NetWorthSnapshot::query()
                ->orderBy('as_of')
                ->get(['as_of', 'net_worth', 'total_assets', 'total_liabilities', 'total_cash']),
            'assetTypes' => ['investment', 'property', 'cash', 'vehicle', 'other'],
            'liabilityTypes' => ['mortgage', 'loan', 'student_loan', 'credit_card', 'other'],
        ]);
    }

    /** Record today's net-worth snapshot (one per day). */
    public function snapshot()
    {
        $this->netWorth->snapshot();

        return back()->with(['message' => 'Net-worth snapshot saved.', 'type' => 'success']);
    }
}
