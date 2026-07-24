<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTransactionRequest extends FormRequest
{
    /**
     * Authorization is enforced at the route/middleware layer and by the
     * BelongsToUser global scope (cross-user route-model binding 404s).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only user-owned overrides are editable. Amount, transaction_date,
     * description and hash come from the bank import and are the source of
     * truth — they are intentionally not accepted here.
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'nullable',
                'integer',
                // BelongsToUser scopes Category, so this only matches the
                // current user's categories — a cross-user id fails.
                Rule::exists((new Category)->getTable(), 'id')
                    ->where('user_id', $this->user()->id),
            ],
            'is_excluded' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.exists' => 'The selected category does not exist.',
        ];
    }
}
