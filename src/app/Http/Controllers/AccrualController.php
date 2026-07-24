<?php

namespace App\Http\Controllers;

use App\Services\AccrualCalculator;
use Carbon\CarbonImmutable;
use Inertia\Inertia;

class AccrualController extends Controller
{
    public function __construct(private readonly AccrualCalculator $calculator) {}

    public function index()
    {
        $now = CarbonImmutable::now();
        $rows = $this->calculator->snapshot($now);

        $totals = [
            'monthly' => round((float) $rows->sum('monthly'), 2),
            'accrued' => round((float) $rows->sum('accrued'), 2),
            'spent' => round((float) $rows->sum('spent'), 2),
            'variance' => round((float) $rows->sum('variance'), 2),
            'expense_count' => $rows->count(),
        ];

        return Inertia::render('Accrual/Index', [
            'title' => 'Accrual tracker',
            'asOf' => $now->format('Y-m-d'),
            'rows' => $rows->values(),
            'totals' => $totals,
        ]);
    }
}
