<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Services\CompanyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Company profile (traceability 15-02 / 12-02). NEVER creates a second
 * company — only CompanyService::update() mutates THE singleton row.
 */
class CompanyController extends Controller
{
    public function __construct(protected CompanyService $companyService) {}

    public function edit(Request $request): View
    {
        return view('company.edit', [
            'company' => $request->user()->company()->firstOrFail(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'legal_name' => ['nullable', 'string', 'max:191'],
            'email' => ['nullable', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:191'],
            'address_line1' => ['nullable', 'string', 'max:191'],
            'address_line2' => ['nullable', 'string', 'max:191'],
            'area' => ['nullable', 'string', 'max:191'],
            'district' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'trade_license_no' => ['nullable', 'string', 'max:64'],
            'tin' => ['nullable', 'string', 'max:64'],
            'bin' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'in:en,bn'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'date_format' => ['nullable', 'string', 'max:32'],
            'number_format' => ['nullable', 'string', 'max:32'],
        ], [], [
            'name' => 'company name',
        ]);

        $this->companyService->update($data, $request->user());

        return redirect()
            ->route('company.edit')
            ->with('status', 'Company profile updated.');
    }
}
