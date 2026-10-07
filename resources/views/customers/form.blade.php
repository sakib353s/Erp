@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New customer' : 'Edit '.$customer->name)

@section('content')
    <x-ui.page-header
        eyebrow="Sales & CRM"
        :title="$mode === 'create' ? 'New customer' : 'Edit customer'"
        :subtitle="$mode === 'create'
            ? 'Phone and e-mail are unique per company — search before adding a duplicate party.'
            : 'Changes are audited with who changed what.'">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('customers.index') }}">Back to customers</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form method="POST"
          action="{{ $mode === 'create' ? route('customers.store') : route('customers.update', $customer) }}"
          class="erp-form">
        @csrf
        @if ($mode === 'edit') @method('PUT') @endif

        @if ($errors->any())
            <div class="erp-note erp-note-danger mb-3">
                <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
                <div>
                    <strong>This customer was not saved.</strong>
                    <ul class="mb-0 ps-3">
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="erp-form-grid">
            <section class="erp-card">
                <h2 class="erp-card-title mb-3">Identity</h2>

                <div class="mb-3">
                    <label class="form-label" for="name">Customer name <span class="text-danger">*</span></label>
                    <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                           value="{{ old('name', $customer->name) }}" required maxlength="191" autocomplete="off">
                    @error('name')<span class="erp-field-error">{{ $message }}</span>@enderror
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="type">Type</label>
                        <select class="form-select" id="type" name="type">
                            @foreach ($types as $type)
                                <option value="{{ $type }}" @selected(old('type', $customer->type) === $type)>{{ ucfirst($type) }}</option>
                            @endforeach
                        </select>
                        <span class="erp-help">Business customers get BIN/VAT fields on the invoice.</span>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="code">Customer code</label>
                        <input class="form-control" id="code" name="code"
                               value="{{ old('code', $customer->code) }}" placeholder="Auto (CUST-00001)" maxlength="32">
                        <span class="erp-help">Leave empty to generate the next code.</span>
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <div class="col-sm-6">
                        <label class="form-label" for="phone">Phone</label>
                        <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone"
                               value="{{ old('phone', $customer->phone) }}" inputmode="tel" maxlength="32">
                        @error('phone')<span class="erp-field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="alt_phone">Alternate phone</label>
                        <input class="form-control" id="alt_phone" name="alt_phone"
                               value="{{ old('alt_phone', $customer->alt_phone) }}" inputmode="tel" maxlength="32">
                    </div>
                </div>

                <div class="row g-3 mt-0">
                    <div class="col-sm-6">
                        <label class="form-label" for="email">E-mail</label>
                        <input class="form-control @error('email') is-invalid @enderror" id="email" name="email" type="email"
                               value="{{ old('email', $customer->email) }}" maxlength="191">
                        @error('email')<span class="erp-field-error">{{ $message }}</span>@enderror
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="contact_person">Contact person</label>
                        <input class="form-control" id="contact_person" name="contact_person"
                               value="{{ old('contact_person', $customer->contact_person) }}" maxlength="128">
                    </div>
                </div>
            </section>

            <section class="erp-card">
                <h2 class="erp-card-title mb-3">Address &amp; tax</h2>

                <div class="mb-3">
                    <label class="form-label" for="address_line1">Address</label>
                    <input class="form-control" id="address_line1" name="address_line1"
                           value="{{ old('address_line1', $customer->address_line1) }}" maxlength="191">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="district_id">District</label>
                    <select class="form-select" id="district_id" name="district_id">
                        <option value="">Not set</option>
                        @foreach ($districts as $district)
                            <option value="{{ $district->id }}" @selected((int) old('district_id', $customer->district_id) === $district->id)>
                                {{ $district->name }}@if ($district->division) · {{ $district->division }}@endif
                            </option>
                        @endforeach
                    </select>
                </div>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="bin">BIN</label>
                        <input class="form-control" id="bin" name="bin" value="{{ old('bin', $customer->bin) }}" maxlength="32">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="tax_vat_no">VAT registration no.</label>
                        <input class="form-control" id="tax_vat_no" name="tax_vat_no"
                               value="{{ old('tax_vat_no', $customer->tax_vat_no) }}" maxlength="32">
                    </div>
                </div>

                <div class="mt-3">
                    <label class="form-label" for="notes">Internal notes</label>
                    <textarea class="form-control" id="notes" name="notes" rows="3" maxlength="2000">{{ old('notes', $customer->notes) }}</textarea>
                    <span class="erp-help">Visible to staff only. Never printed on customer documents.</span>
                </div>
            </section>

            <section class="erp-card">
                <h2 class="erp-card-title mb-3">Commercial terms</h2>

                <div class="row g-3">
                    <div class="col-sm-6">
                        <label class="form-label" for="customer_group_id">Price / discount group</label>
                        <select class="form-select" id="customer_group_id" name="customer_group_id">
                            <option value="">No group</option>
                            @foreach ($groups as $group)
                                <option value="{{ $group->id }}" @selected((int) old('customer_group_id', $customer->customer_group_id) === $group->id)>
                                    {{ $group->name }}
                                </option>
                            @endforeach
                        </select>
                        <span class="erp-help">Group pricing rules apply automatically at billing.</span>
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label" for="segment">Segment</label>
                        <select class="form-select" id="segment" name="segment">
                            <option value="">Not set</option>
                            @foreach ($segments as $segment)
                                <option value="{{ $segment }}" @selected(old('segment', $customer->segment) === $segment)>
                                    {{ ucfirst(str_replace('_', ' ', $segment)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                @if ($mode === 'create')
                    <div class="row g-3 mt-0">
                        <div class="col-sm-6">
                            <label class="form-label" for="credit_limit">Credit limit (৳)</label>
                            <input class="form-control" id="credit_limit" name="credit_limit" inputmode="decimal"
                                   value="{{ old('credit_limit', 0) }}">
                            <span class="erp-help">0 = cash only. Later changes require a reason and are recorded.</span>
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="credit_days">Credit days</label>
                            <input class="form-control" id="credit_days" name="credit_days" inputmode="numeric"
                                   value="{{ old('credit_days', 0) }}">
                        </div>
                    </div>

                    <div class="row g-3 mt-0">
                        <div class="col-sm-6">
                            <label class="form-label" for="opening_balance">Opening balance (৳)</label>
                            <input class="form-control" id="opening_balance" name="opening_balance" inputmode="decimal"
                                   value="{{ old('opening_balance', 0) }}">
                        </div>
                        <div class="col-sm-6">
                            <label class="form-label" for="opening_balance_type">Opening side</label>
                            <select class="form-select" id="opening_balance_type" name="opening_balance_type">
                                <option value="due" @selected(old('opening_balance_type', 'due') === 'due')>Customer owes us</option>
                                <option value="advance" @selected(old('opening_balance_type') === 'advance')>We hold an advance</option>
                            </select>
                        </div>
                    </div>
                @else
                    <div class="erp-note mt-3">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        <div>
                            Credit limit is controlled from the profile with a recorded reason,
                            so it is not editable here. Current: ৳ {{ number_format((float) $customer->credit_limit, 2) }}
                            @if ($customer->credit_days > 0) over {{ $customer->credit_days }} days @endif
                        </div>
                    </div>
                @endif

                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                           @checked(old('is_active', $customer->is_active ?? true))>
                    <label class="form-check-label" for="is_active">Active — can be selected on new documents</label>
                </div>
            </section>
        </div>

        <div class="erp-form-actions">
            <a class="btn btn-outline-secondary" href="{{ $mode === 'edit' ? route('customers.show', $customer) : route('customers.index') }}">Cancel</a>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-check2" aria-hidden="true"></i>
                {{ $mode === 'create' ? 'Create customer' : 'Save changes' }}
            </button>
        </div>
    </form>
@endsection
