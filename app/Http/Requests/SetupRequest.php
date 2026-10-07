<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * First-boot payload (§49): one-time token + THE company's profile +
 * the administrator account. The password passes the REAL policy inside
 * SetupService (same validator the app uses forever after).
 */
class SetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // GuardSetup middleware controls availability
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:256'],

            'company' => ['required', 'array'],
            'company.name' => ['required', 'string', 'max:191'],
            'company.legal_name' => ['nullable', 'string', 'max:191'],
            'company.email' => ['nullable', 'email', 'max:191'],
            'company.phone' => ['nullable', 'string', 'max:32'],
            'company.address_line1' => ['nullable', 'string', 'max:191'],
            'company.address_line2' => ['nullable', 'string', 'max:191'],
            'company.area' => ['nullable', 'string', 'max:128'],
            'company.district' => ['nullable', 'string', 'max:64'],
            'company.postal_code' => ['nullable', 'string', 'max:16'],
            'company.currency' => ['nullable', 'string', 'size:3'],
            'company.fiscal_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'company.trade_license_no' => ['nullable', 'string', 'max:64'],
            'company.tin' => ['nullable', 'string', 'max:32'],
            'company.bin' => ['nullable', 'string', 'max:32'],
            'company.branch_name' => ['nullable', 'string', 'max:191'],

            'admin' => ['required', 'array'],
            'admin.name' => ['required', 'string', 'max:191'],
            'admin.email' => ['required', 'email', 'max:191', 'unique:users,email'],
            'admin.password' => ['required', 'string', 'max:200', 'confirmed'],
        ];
    }

    public function attributes(): array
    {
        return [
            'token' => 'setup token',
            'company.name' => 'company name',
            'admin.name' => 'your name',
            'admin.email' => 'your e-mail address',
            'admin.password' => 'your password',
        ];
    }
}
