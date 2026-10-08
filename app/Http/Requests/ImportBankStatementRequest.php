<?php

namespace App\Http\Requests;

use App\Domain\CashBank\Services\BankStatementImportService;
use Illuminate\Foundation\Http\FormRequest;

class ImportBankStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Which reconcile key is needed depends on the account, and only the
        // controller knows the account — so this is the floor (either key), and
        // the instrument's own key is asserted again where the account is known.
        return (bool) ($this->user()?->can('bank.reconcile') || $this->user()?->can('wallets.reconcile'));
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.BankStatementImportService::MAX_KILOBYTES],
            'preview' => ['sometimes', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'file' => 'statement file',
        ];
    }

    public function messages(): array
    {
        return [
            'file.mimes' => 'A statement is a CSV export from the bank — that file is neither a CSV nor a text file.',
            'file.max' => 'That file is larger than :max KB. A statement that size is not a statement — split it by month.',
        ];
    }
}
