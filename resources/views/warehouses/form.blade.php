@extends('layouts.app')

@section('page_title', $mode === 'create' ? 'New warehouse' : 'Edit warehouse')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $mode === 'create' ? 'New warehouse' : 'Edit: '.$warehouse->name }}</h1>
            <p class="erp-page-sub">Warehouse codes are unique within their branch.</p>
        </div>
        <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">Back to warehouses</a>
    </div>

    <form method="POST" action="{{ $mode === 'create' ? route('warehouses.store') : route('warehouses.update', $warehouse) }}">
        @csrf
        @if ($mode === 'edit')@method('PUT')@endif

        <div class="row g-3">
            <div class="col-lg-7">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Warehouse details</h2></header>
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="branch_id">Branch <span class="text-danger">*</span></label>
                            <select class="form-select @error('branch_id') is-invalid @enderror" id="branch_id" name="branch_id" required>
                                <option value="">— select branch —</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}" @selected((int) old('branch_id', $warehouse->branch_id) === $b->id)>
                                        {{ $b->name }} ({{ $b->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('branch_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="code">Code <span class="text-danger">*</span></label>
                            <input class="form-control text-uppercase @error('code') is-invalid @enderror" id="code"
                                   name="code" value="{{ old('code', $warehouse->code) }}" required maxlength="32">
                            @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="name">Name <span class="text-danger">*</span></label>
                            <input class="form-control @error('name') is-invalid @enderror" id="name" name="name"
                                   value="{{ old('name', $warehouse->name) }}" required>
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="address">Address</label>
                            <input class="form-control" id="address" name="address"
                                   value="{{ old('address', $warehouse->address) }}">
                            @error('address')<div class="text-danger small">{{ $message }}</div>@enderror
                        </div>
                    </div>
                </section>
            </div>

            <div class="col-lg-5">
                <section class="erp-card">
                    <header class="erp-card-head"><h2 class="erp-card-title">Flags</h2></header>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_active" name="is_active" value="1"
                               @checked(old('is_active', $warehouse->is_active ?? true))>
                        <label class="form-check-label" for="is_active">Active — selectable as a context</label>
                    </div>
                    @error('is_active')<div class="text-danger small">{{ $message }}</div>@enderror
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="is_default" name="is_default" value="1"
                               @checked(old('is_default', $warehouse->is_default ?? false))>
                        <label class="form-check-label" for="is_default">Default warehouse of this branch</label>
                    </div>
                    @error('is_default')<div class="text-danger small">{{ $message }}</div>@enderror
                </section>

                <div class="d-grid gap-2 mt-3">
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check-lg" aria-hidden="true"></i>
                        {{ $mode === 'create' ? 'Create warehouse' : 'Save changes' }}
                    </button>
                    <a class="btn btn-outline-secondary" href="{{ route('warehouses.index') }}">Cancel</a>
                </div>
            </div>
        </div>
    </form>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
