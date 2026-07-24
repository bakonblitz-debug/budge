<?php

namespace App\Http\Requests;

use App\Services\Statements\ParserRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportStatementRequest extends FormRequest
{
    /**
     * Authorization is at the route/middleware layer (DevAutoLogin for now,
     * real auth later). The BelongsToUser global scope on BankAccount enforces
     * that bank_account_id can only resolve to the current user's accounts.
     */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $registry = app(ParserRegistry::class);
        $allFormats = array_map(fn ($p) => $p->getFormat(), $registry->all());
        $extensions = $this->allowedExtensions($registry);

        return [
            // Strict size cap, real mime check by Symfony, extension whitelist.
            // PDFs from banks are typically <2MB; 10MB gives generous headroom.
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:'.implode(',', $extensions),
                'mimetypes:application/pdf,text/csv,text/plain',
                // defence in depth: also assert by extension since some clients
                // send wrong mime types
                'extensions:'.implode(',', $extensions),
            ],
            'bank_account_id' => ['required', 'integer', Rule::exists('bank_accounts', 'id')],
            'period_year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['required', 'integer', 'min:1', 'max:12'],
            'bank_format' => ['required', 'string', Rule::in($allFormats)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'The file must not be larger than 10MB.',
            'file.mimes' => 'Unsupported file type. Accepted: PDF.',
            'file.extensions' => 'Unsupported file extension.',
            'bank_account_id.exists' => 'The selected bank account does not exist.',
            'bank_format.in' => 'Unsupported statement format.',
        ];
    }

    /** @return string[] */
    private function allowedExtensions(ParserRegistry $registry): array
    {
        $exts = [];
        foreach ($registry->all() as $parser) {
            foreach ($parser->getAcceptedExtensions() as $ext) {
                $exts[] = strtolower($ext);
            }
        }

        return array_values(array_unique($exts));
    }
}
