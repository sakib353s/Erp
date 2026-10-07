@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New branch' : 'Edit branch')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New branch' : 'Edit: '.$branch->name }}</h1>
            <p class="erp-page-sub">Branch code is unique per company and permanent after creation.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('branches.index') }}">Back to branches</a>
    </div>

    <form method="POST" action="{{ $mode === 'create' ? route('branches.store') : route('branches.update', $branch) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-8">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Branch details</h2></header>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
                            <input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code"
                                   name="code" value="{{ old('code', $branch->code) }}" required maxlength="32">
                            <div class="form-text">e.g. DHK, CTG — unique per company.</div>
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                                   value="{{ old('name', $branch->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="phone">Phone</label>
                            <input class="form-control @error('phone') is-invalid @enderror" id="phone" name="phone"
                                   value="{{ old('phone', $branch->phone) }}">
                            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="email">E-mail</label>
                            <input class="form-control @error('email') is-invalid @enderror" type="email" id="email"
                                   name="email" value="{{ old('email', $branch->email) }}">
                            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address_line1">Address line 1</label>
                            <input class="form-control" id="address_line1" name="address_line1"
                                   value="{{ old('address_line1', $branch->address_line1) }}">
                            @error('address_line1')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address_line2">Address line 2</label>
                            <input class="form-control" id="address_line2" name="address_line2"
                                   value="{{ old('address_line2', $branch->address_line2) }}">
                            @error('address_line2')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="area">Area / Thana</label>
                            <input class="form-control" id="area" name="area" value="{{ old('area', $branch->area) }}">
                            @error('area')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="district">District</label>
                            <input class="form-control" id="district" name="district"
                                   value="{{ old('district', $branch->district) }}">
                            @error('district')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="postal_code">Postal code</label>
                            <input class="form-control" id="postal_code" name="postal_code"
                                   value="{{ old('postal_code', $branch->postal_code) }}">
                            @error('postal_code')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="operating_status">Operating status</label>
                            <select class="form-select" id="operating_status" name="operating_status">
                                @foreach (['active', 'seasonal', 'temporarily_closed', 'closed'] as $s)
                                    <option value="{{ $s }}" @selected(old('operating_status', $branch->operating_status ?? 'active') === $s)>
                                        {{ ucwords(str_replace('_', ' ', $s)) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('operating_status')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-4">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Flags</h2></header>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                               @checked(old('is_active', $branch->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active — usable as a context</label>
                    </div>
                    @error('is_active')<div class="text-danger small">{{ $message }}</div>@enderror
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1"
                               @checked(old('is_default', $branch->is_default ?? false))>
                        <label class="form-check-label" for="is_default">Default branch (only one may hold this)</label>
                    </div>
                    @error('is_default')<div class="text-danger small">{{ $message }}</div>@enderror
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create branch' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('branches.index') }}">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
