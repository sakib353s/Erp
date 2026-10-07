@extends('layouts.app')

@section('page_title', 'Company profile')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Company profile</h1>
            <p class="erp-page-sub">One company per instance. Updates are audited and never create a second company.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('settings.show', 'general') }}">General settings</a>
    </div>

    <form method="POST" action="{{ route('company.update') }}">
        @csrf
        @method('PUT')

        <div class="row g-3">
            <div class="col-lg-7">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Identity</h2></header>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label" for="name">Company name <span class="text-danger">*</span></label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                                   value="{{ old('name', $company->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="legal_name">Legal name</label>
                            <input class="form-control @error('legal_name') is-invalid @enderror" id="legal_name"
                                   name="legal_name" value="{{ old('legal_name', $company->legal_name) }}">
                            @error('legal_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">E-mail</label>
                            <input class="form-control @error('email') is-invalid @enderror" type="email" id="email"
                                   name="email" value="{{ old('email', $company->email) }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone"
                                   name="phone" value="{{ old('phone', $company->phone) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="trade_license_no">Trade licence</label>
                            <input class="form-control @error('trade_license_no') is-invalid @enderror" id="trade_license_no"
                                   name="trade_license_no" value="{{ old('trade_license_no', $company->trade_license_no) }}">
                            @error('trade_license_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="tin">TIN</label>
                            <input class="form-control @error('tin') is-invalid @enderror" id="tin"
                                   name="tin" value="{{ old('tin', $company->tin) }}">
                            @error('tin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="bin">BIN</label>
                            <input class="form-control @error('bin') is-invalid @enderror" id="bin"
                                   name="bin" value="{{ old('bin', $company->bin) }}">
                            @error('bin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>

                <section class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">Address</h2></header>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label" for="address_line1">Address line 1</label>
                            <input class="form-control @error('address_line1') is-invalid @enderror" id="address_line1"
                                   name="address_line1" value="{{ old('address_line1', $company->address_line1) }}">
                            @error('address_line1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address_line2">Address line 2</label>
                            <input class="form-control @error('address_line2') is-invalid @enderror" id="address_line2"
                                   name="address_line2" value="{{ old('address_line2', $company->address_line2) }}">
                            @error('address_line2')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="area">Area</label>
                            <input class="form-control @error('area') is-invalid @enderror" id="area"
                                   name="area" value="{{ old('area', $company->area) }}">
                            @error('area')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="district">District</label>
                            <input class="form-control @error('district') is-invalid @enderror" id="district"
                                   name="district" value="{{ old('district', $company->district) }}">
                            @error('district')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="postal_code">Postal code</label>
                            <input class="form-control @error('postal_code') is-invalid @enderror" id="postal_code"
                                   name="postal_code" value="{{ old('postal_code', $company->postal_code) }}">
                            @error('postal_code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Locale &amp; fiscal</h2></header>
                    <div class="mb-3">
                        <label class="form-label" for="locale">Primary locale</label>
                        <select class="form-select" id="locale" name="locale">
                            <option value="en" @selected(old('locale', $company->locale) === 'en')>English</option>
                            <option value="bn" @selected(old('locale', $company->locale) === 'bn')>বাংলা</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="fiscal_year_start_month">Fiscal year start month</label>
                        <select class="form-select" id="fiscal_year_start_month" name="fiscal_year_start_month">
                            @for ($m = 1; $m <= 12; $m++)
                                <option value="{{ $m }}" @selected((int) old('fiscal_year_start_month', $company->fiscal_year_start_month) === $m)>
                                    {{ DateTime::createFromFormat('!m', $m)->format('F') }}
                                </option>
                            @endfor
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="date_format">Date format</label>
                        <input class="form-control @error('date_format') is-invalid @enderror" id="date_format"
                               name="date_format" value="{{ old('date_format', $company->date_format ?? 'd M Y') }}">
                        @error('date_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="number_format">Number format</label>
                        <input class="form-control @error('number_format') is-invalid @enderror" id="number_format"
                               name="number_format" value="{{ old('number_format', $company->number_format ?? '#,##0.00') }}">
                        @error('number_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i> Save company profile
                    </button>
                </div>
            </div>
        </div>
    </form>
@endsection
