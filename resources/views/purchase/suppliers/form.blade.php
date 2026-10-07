@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New supplier' : 'Edit '.$supplier->name)

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Suppliers"
        :title="$mode === 'create' ? 'New supplier' : 'Edit '.$supplier->name"
        subtitle="Duplicates are refused by name, phone and BIN — a supplier entered twice is how a bill gets paid twice."
        :pin="$mode === 'edit'">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ $mode === 'edit' ? route('suppliers.show', $supplier) : route('suppliers.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Back
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('supplier')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <form method="POST" action="{{ $mode === 'create' ? route('suppliers.store') : route('suppliers.update', $supplier) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="erp-split">
            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Party</h2>
                </div>
                <div class="erp-form-grid px-3 pb-3">
                    <div class="erp-form-field">
                        <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                        <input class="form-control @error('name') is-invalid @enderror" id="name" name="name" maxlength="191"
                               value="{{ old('name', $supplier->name) }}" required>
                        @error('name')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="code">Code</label>
                        <input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code" name="code" maxlength="32"
                               value="{{ old('code', $supplier->code) }}" placeholder="Generated when left blank">
                        @error('code')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="contact_person">Contact person</label>
                        <input class="form-control" id="contact_person" name="contact_person" maxlength="128"
                               value="{{ old('contact_person', $supplier->contact_person) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="category">Category</label>
                        <select class="form-select" id="category" name="category">
                            <option value="">— not set —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category }}" @selected(old('category', $supplier->category) === $category)>{{ ucfirst($category) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control" id="phone" name="phone" maxlength="32" value="{{ old('phone', $supplier->phone) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="phone_alt">Alternate phone</label>
                        <input class="form-control" id="phone_alt" name="phone_alt" maxlength="32" value="{{ old('phone_alt', $supplier->phone_alt) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="email">E-mail</label>
                        <input class="form-control @error('email') is-invalid @enderror" type="email" id="email" name="email" maxlength="191"
                               value="{{ old('email', $supplier->email) }}">
                        @error('email')<div class="erp-field-error">{{ $message }}</div>@enderror
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="district_id">District</label>
                        <select class="form-select" id="district_id" name="district_id">
                            <option value="">— not set —</option>
                            @foreach ($districts as $district)
                                <option value="{{ $district->id }}" @selected((int) old('district_id', $supplier->district_id) === $district->id)>{{ $district->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="address_line1">Address</label>
                        <input class="form-control" id="address_line1" name="address_line1" maxlength="191" value="{{ old('address_line1', $supplier->address_line1) }}">
                    </div>
                </div>

                <div class="erp-card-head">
                    <h2 class="erp-card-title">Tax &amp; terms</h2>
                </div>
                <div class="erp-form-grid px-3 pb-3">
                    <div class="erp-form-field">
                        <label class="form-label" for="bin">BIN</label>
                        <input class="form-control" id="bin" name="bin" maxlength="32" value="{{ old('bin', $supplier->bin) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="tin">TIN</label>
                        <input class="form-control" id="tin" name="tin" maxlength="32" value="{{ old('tin', $supplier->tin) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="payment_terms_days">Payment terms (days)</label>
                        <input class="form-control" type="number" min="0" max="365" id="payment_terms_days" name="payment_terms_days"
                               value="{{ old('payment_terms_days', $supplier->payment_terms_days ?? 0) }}">
                        <div class="erp-help">0 means cash on delivery — the bill chain reads this when it lands.</div>
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="credit_limit">Credit limit (৳)</label>
                        <input class="form-control" type="number" step="0.01" min="0" id="credit_limit" name="credit_limit"
                               value="{{ old('credit_limit', $supplier->credit_limit ?? 0) }}">
                    </div>
                </div>

                <div class="erp-card-head">
                    <h2 class="erp-card-title">Payment destination</h2>
                </div>
                <div class="erp-form-grid px-3 pb-3">
                    <div class="erp-form-field">
                        <label class="form-label" for="bank_name">Bank</label>
                        <input class="form-control" id="bank_name" name="bank_name" maxlength="96" value="{{ old('bank_name', $supplier->bank_name) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="bank_account_no">Account number</label>
                        <input class="form-control" id="bank_account_no" name="bank_account_no" maxlength="48" value="{{ old('bank_account_no', $supplier->bank_account_no) }}">
                    </div>
                    <div class="erp-form-field">
                        <label class="form-label" for="mobile_wallet">Mobile wallet</label>
                        <input class="form-control" id="mobile_wallet" name="mobile_wallet" maxlength="32" value="{{ old('mobile_wallet', $supplier->mobile_wallet) }}">
                    </div>
                </div>
            </div>

            <div class="erp-card">
                <div class="erp-card-head">
                    <h2 class="erp-card-title">Status</h2>
                </div>
                <div class="px-3 pb-3">
                    <div class="erp-form-field">
                        <label class="form-label" for="is_active">Receiving documents</label>
                        <select class="form-select" id="is_active" name="is_active">
                            <option value="1" @selected(old('is_active', $supplier->is_active ?? true))>Active — can be ordered from</option>
                            <option value="0" @selected(old('is_active') === '0')>Inactive — kept for history only</option>
                        </select>
                    </div>
                    <div class="erp-form-field mt-3">
                        <label class="form-label" for="notes">Notes</label>
                        <textarea class="form-control" id="notes" name="notes" rows="4" maxlength="2000">{{ old('notes', $supplier->notes) }}</textarea>
                    </div>
                    <div class="erp-help mt-2">
                        Blacklisting is a separate action on the supplier profile: it needs a reason and is audited,
                        because barring a supplier is a decision somebody must own.
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2 mt-3">
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check-lg" aria-hidden="true"></i>
                {{ $mode === 'create' ? 'Create supplier' : 'Save changes' }}
            </button>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.index') }}">Cancel</a>
        </div>
    </form>

    <x-ui.related-pages />
@endsection
