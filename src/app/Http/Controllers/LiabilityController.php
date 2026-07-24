<?php

namespace App\Http\Controllers;

use App\Models\Liability;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LiabilityController extends Controller
{
    private const TYPES = ['mortgage', 'loan', 'student_loan', 'credit_card', 'other'];

    public function store(Request $request)
    {
        Liability::create($this->validateLiability($request));

        return back()->with(['message' => 'Liability added.', 'type' => 'success']);
    }

    public function update(Request $request, Liability $liability)
    {
        $liability->update($this->validateLiability($request));

        return back()->with(['message' => 'Liability updated.', 'type' => 'success']);
    }

    public function destroy(Liability $liability)
    {
        $liability->delete();

        return back()->with(['message' => 'Liability removed.', 'type' => 'success']);
    }

    private function validateLiability(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(self::TYPES)],
            'institution' => ['nullable', 'string', 'max:255'],
            'balance' => ['required', 'numeric', 'min:0', 'max:99999999999.99'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:999.99'],
            'is_active' => ['boolean'],
        ]);
    }
}
