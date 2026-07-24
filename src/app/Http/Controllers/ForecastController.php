<?php

namespace App\Http\Controllers;

use App\Services\CashFlowForecaster;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ForecastController extends Controller
{
    public function __construct(private readonly CashFlowForecaster $forecaster) {}

    public function index(Request $request)
    {
        $days = (int) $request->integer('days', 60);
        $days = max(7, min(365, $days));

        // Spending baseline window: an explicit "since" date, or a months-back
        // preset. Both are optional; the forecaster falls back to a default.
        $baselineStart = $request->filled('baseline_start') ? $request->string('baseline_start')->toString() : null;
        $baselineMonths = $request->filled('baseline_months') ? max(1, min(24, (int) $request->integer('baseline_months'))) : null;

        return Inertia::render('Forecast/Index', [
            'title' => 'Cash-flow forecast',
            'forecast' => $this->forecaster->forecast($days, $baselineStart, $baselineMonths),
        ]);
    }
}
