<?php

namespace App\Http\Requests;

use App\Domain\Foundation\Branch;
use App\Domain\Foundation\Concerns\BranchScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * §12-08 — a transfer raised from a branch's own desk.
 *
 * The rules are the stock-transfer rules with the branch fixed: the goods must
 * leave from a warehouse this branch owns, and they must arrive at a warehouse
 * of another branch. That last rule is the whole point of the screen — a move
 * inside one branch is a stockroom job, not a branch transfer — and it is
 * enforced here rather than in a help text, because a transfer that never
 * crosses a branch would quietly make the branch comparison wrong.
 */
class StoreBranchTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $branchId = $this->branch()?->id;

        return [
            'from_warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('branch_id', $branchId),
                Rule::notIn([$this->input('to_warehouse_id')])],
            'to_warehouse_id' => ['required', 'integer',
                Rule::exists('warehouses', 'id')->where('company_id', $this->branch()?->company_id)
                    ->whereNot('branch_id', $branchId)],
            'transfer_date' => ['required', 'date'],
            'narration' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'lines.*.qty_sent' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'from_warehouse_id' => 'source warehouse (this branch)',
            'to_warehouse_id' => 'destination warehouse (another branch)',
            'lines' => 'transfer lines',
            'lines.*.qty_sent' => 'quantity',
        ];
    }

    public function messages(): array
    {
        return [
            'from_warehouse_id.exists' => 'The stock must leave from a warehouse of this branch.',
            'to_warehouse_id.exists' => 'The stock must arrive at a warehouse belonging to another branch.',
        ];
    }

    /** The branch the route is about, however the binding handed it over. */
    protected function branch(): ?Branch
    {
        $branch = $this->route('branch');

        if ($branch instanceof Branch) {
            return $branch;
        }

        return Branch::withoutGlobalScope(BranchScope::class)
            ->where('company_id', (int) $this->user()->company_id)
            ->whereKey($branch)
            ->first();
    }
}
