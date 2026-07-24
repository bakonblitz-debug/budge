<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    private const TYPES = ['investment', 'property', 'cash', 'vehicle', 'other'];

    public function store(Request $request)
    {
        Asset::create($this->validateAsset($request));

        return back()->with(['message' => 'Asset added.', 'type' => 'success']);
    }

    public function update(Request $request, Asset $asset)
    {
        $asset->update($this->validateAsset($request));

        return back()->with(['message' => 'Asset updated.', 'type' => 'success']);
    }

    public function destroy(Asset $asset)
    {
        $asset->delete();

        return back()->with(['message' => 'Asset removed.', 'type' => 'success']);
    }

    private function validateAsset(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'institution' => ['nullable', 'string', 'max:255'],
            'current_value' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
    }
}
