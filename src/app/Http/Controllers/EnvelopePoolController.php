<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\EnvelopePool;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class EnvelopePoolController extends Controller
{
    public function index()
    {
        $pools = EnvelopePool::query()
            ->with('category:id,name,icon,color,parent_id')
            ->with('category.parent:id,name')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (EnvelopePool $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'monthly_accrual' => (float) $p->monthly_accrual,
                'calculated_balance' => $p->calculated_balance,
                'start_date' => optional($p->start_date)->format('Y-m-d'),
                'is_active' => $p->is_active,
                'category' => $p->category ? [
                    'id' => $p->category->id,
                    'name' => $p->category->name,
                    'icon' => $p->category->icon,
                    'color' => $p->category->color,
                    'parent' => $p->category->parent ? ['name' => $p->category->parent->name] : null,
                ] : null,
            ]);

        $totalAccrual = round($pools->where('is_active', true)->sum('monthly_accrual'), 2);
        $totalBalance = round($pools->where('is_active', true)->sum('calculated_balance'), 2);

        return Inertia::render('EnvelopePools/Index', [
            'title' => 'Envelope pools',
            'pools' => $pools,
            'totals' => [
                'monthly_accrual' => $totalAccrual,
                'balance' => $totalBalance,
            ],
            'categories' => Category::query()
                ->with('parent:id,name')
                ->whereNotIn('id', EnvelopePool::query()->pluck('category_id'))
                ->select('id', 'name', 'parent_id', 'icon', 'color')
                ->orderByUsage()
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePool($request);
        EnvelopePool::create($validated + ['current_balance' => 0]);

        return back()->with(['message' => "Pool '{$validated['name']}' added.", 'type' => 'success']);
    }

    public function update(Request $request, EnvelopePool $envelopePool)
    {
        $validated = $this->validatePool($request, $envelopePool->id);
        $envelopePool->update($validated);

        return back()->with(['message' => "'{$envelopePool->name}' updated.", 'type' => 'success']);
    }

    public function destroy(EnvelopePool $envelopePool)
    {
        $name = $envelopePool->name;
        $envelopePool->delete();

        return back()->with(['message' => "'{$name}' deleted.", 'type' => 'success']);
    }

    private function validatePool(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id'),
                Rule::unique('envelope_pools', 'category_id')->ignore($ignoreId),
            ],
            'monthly_accrual' => ['required', 'numeric', 'min:0', 'max:99999.99'],
            'start_date' => ['required', 'date'],
            'is_active' => ['boolean'],
        ], [
            'category_id.unique' => 'There is already an envelope pool for that category.',
        ]);
    }
}
