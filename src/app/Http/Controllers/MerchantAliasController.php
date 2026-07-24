<?php

namespace App\Http\Controllers;

use App\Models\MerchantAlias;
use App\Models\Transaction;
use App\Services\MerchantNormalizer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class MerchantAliasController extends Controller
{
    private const MATCH_TYPES = ['contains', 'starts_with', 'ends_with', 'exact', 'regex'];

    public function __construct(private readonly MerchantNormalizer $normalizer) {}

    public function index()
    {
        return Inertia::render('Merchants/Index', [
            'title' => 'Merchant names',
            'aliases' => MerchantAlias::query()
                ->orderByDesc('priority')
                ->orderBy('display_name')
                ->get(),
            'matchTypes' => self::MATCH_TYPES,
            // A small sample of raw descriptions to help the user write aliases.
            'sampleDescriptions' => Transaction::query()
                ->select('description')
                ->distinct()
                ->orderBy('description')
                ->limit(50)
                ->pluck('description'),
        ]);
    }

    public function store(Request $request)
    {
        MerchantAlias::create($this->validateAlias($request));
        $this->reapply();

        return back()->with(['message' => 'Merchant alias added.', 'type' => 'success']);
    }

    public function update(Request $request, MerchantAlias $merchant)
    {
        $merchant->update($this->validateAlias($request));
        $this->reapply();

        return back()->with(['message' => 'Merchant alias updated.', 'type' => 'success']);
    }

    public function destroy(MerchantAlias $merchant)
    {
        $merchant->delete();
        $this->reapply();

        return back()->with(['message' => 'Merchant alias removed.', 'type' => 'success']);
    }

    private function validateAlias(Request $request): array
    {
        return $request->validate([
            'match_type' => ['required', Rule::in(self::MATCH_TYPES)],
            'match_value' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['boolean'],
        ]);
    }

    /**
     * Re-derive merchant_name across all transactions after an alias change so
     * the lists reflect the new rule immediately.
     */
    private function reapply(): void
    {
        $this->normalizer->loadAliases();

        Transaction::query()->chunkById(500, function ($transactions): void {
            foreach ($transactions as $txn) {
                $name = $this->normalizer->normalize($txn->description);
                if ($name !== $txn->merchant_name) {
                    $txn->update(['merchant_name' => $name]);
                }
            }
        });
    }
}
