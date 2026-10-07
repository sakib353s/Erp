@extends('layouts.guest')

@section('page_title', 'First-boot setup')

@section('content')
    <h1 class="erp-auth-title">Instance setup</h1>
    <p class="erp-auth-sub">This screen runs exactly once: create THE company and its administrator account.</p>

    @if ($tokenRequired)
        <div class="alert alert-warning">
            <i class="bi bi-key me-1" aria-hidden="true"></i>
            <strong>No setup token is outstanding.</strong>
            On the server, run <code>php artisan erp:setup-token</code> and paste the printed value below.
            It is shown once, expires shortly, and can only be used a limited number of times.
        </div>
    @endif

    <form method="POST" action="{{ route('setup.store') }}">
        @csrf

        <fieldset class="mb-4">
            <legend class="erp-legend">One-time token</legend>
            <label class="form-label" for="token">Setup token</label>
            <input class="form-control @error('token') is-invalid @enderror" id="token" name="token"
                   value="{{ old('token') }}" required autocomplete="off" spellcheck="false"
                   placeholder="Paste the token printed by erp:setup-token">
            @error('token')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </fieldset>

        <fieldset class="mb-4">
            <legend class="erp-legend">Company</legend>
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="company_name">Company name <span class="text-danger">*</span></label>
                    <input class="form-control @error('company.name') is-invalid @enderror" id="company_name"
                           name="company[name]" value="{{ old('company.name') }}" required>
                    @error('company.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="company_legal_name">Legal name</label>
                    <input class="form-control" id="company_legal_name" name="company[legal_name]"
                           value="{{ old('company.legal_name') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="company_email">Company e-mail</label>
                    <input class="form-control" type="email" id="company_email" name="company[email]"
                           value="{{ old('company.email') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_phone">Phone</label>
                    <input class="form-control" id="company_phone" name="company[phone]"
                           value="{{ old('company.phone') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_trade">Trade licence no.</label>
                    <input class="form-control" id="company_trade" name="company[trade_license_no]"
                           value="{{ old('company.trade_license_no') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_bin">BIN / VAT</label>
                    <input class="form-control" id="company_bin" name="company[bin]" value="{{ old('company.bin') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_tin">TIN</label>
                    <input class="form-control" id="company_tin" name="company[tin]" value="{{ old('company.tin') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_currency">Currency</label>
                    <input class="form-control" id="company_currency" name="company[currency]" maxlength="3"
                           value="{{ old('company.currency', 'BDT') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_fy">Fiscal year start month (1–12)</label>
                    <input class="form-control" type="number" min="1" max="12" id="company_fy"
                           name="company[fiscal_year_start_month]"
                           value="{{ old('company.fiscal_year_start_month', 7) }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="company_address1">Address line 1</label>
                    <input class="form-control" id="company_address1" name="company[address_line1]"
                           value="{{ old('company.address_line1') }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="company_address2">Address line 2</label>
                    <input class="form-control" id="company_address2" name="company[address_line2]"
                           value="{{ old('company.address_line2') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_area">Area / Thana</label>
                    <input class="form-control" id="company_area" name="company[area]"
                           value="{{ old('company.area') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_district">District</label>
                    <input class="form-control" id="company_district" name="company[district]"
                           value="{{ old('company.district') }}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="company_postal">Postal code</label>
                    <input class="form-control" id="company_postal" name="company[postal_code]"
                           value="{{ old('company.postal_code') }}">
                </div>
                <div class="col-12">
                    <label class="form-label" for="company_branch">First branch name</label>
                    <input class="form-control" id="company_branch" name="company[branch_name]"
                           value="{{ old('company.branch_name') }}" placeholder="Defaults to Head Office">
                </div>
            </div>
        </fieldset>

        <fieldset class="mb-4">
            <legend class="erp-legend">Administrator account</legend>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="admin_name">Your name <span class="text-danger">*</span></label>
                    <input class="form-control @error('admin.name') is-invalid @enderror" id="admin_name"
                           name="admin[name]" value="{{ old('admin.name') }}" required autocomplete="name">
                    @error('admin.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_email">Your e-mail <span class="text-danger">*</span></label>
                    <input class="form-control @error('admin.email') is-invalid @enderror" type="email"
                           id="admin_email" name="admin[email]" value="{{ old('admin.email') }}" required
                           autocomplete="username">
                    @error('admin.email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_password">Password <span class="text-danger">*</span></label>
                    <input class="form-control @error('admin.password') is-invalid @enderror" type="password"
                           id="admin_password" name="admin[password]" required autocomplete="new-password">
                    @error('admin.password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="admin_password_confirmation">Confirm password</label>
                    <input class="form-control" type="password" id="admin_password_confirmation"
                           name="admin[password_confirmation]" required autocomplete="new-password">
                </div>
            </div>
            <p class="form-text">The same configurable password policy applied everywhere else is enforced here.</p>
        </fieldset>

        <button class="btn btn-primary w-100" type="submit">
            <i class="bi bi-rocket-takeoff me-1" aria-hidden="true"></i>Create company &amp; sign in
        </button>
    </form>
@endsection
