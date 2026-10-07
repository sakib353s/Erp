@extends('layouts.app')

@section('page_title', 'Coupons')

@section('content')
        <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Coupons</h1>
            <p class="erp-page-sub">Server-validated discounts — percent off, fixed off, free shipping, buy X get Y.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.coupons.usage') }}">Usage</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.coupons.usage', ['view' => 'analytics']) }}">Analytics</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.promotions.index') }}">Promotions</a>
        </div>
    </div>

    @if ($perm('sales.coupons.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Create coupon</h2>
            <form method="POST" action="{{ route('sales.coupons.store') }}" class="row g-2">
                @csrf
                <div class="col-md-2">
                    <label class="form-label" for="code">Code</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code') }}" required maxlength="48">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" required>
                        @foreach (['percent_off', 'fixed_off', 'free_shipping', 'buy_x_get_y'] as $t)
                            <option value="{{ $t }}" @selected(old('type') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="value">Value</label>
                    <input class="form-control" id="value" name="value" type="number" step="0.01" min="0" value="{{ old('value') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="buy_qty">Buy qty</label>
                    <input class="form-control" id="buy_qty" name="buy_qty" type="number" min="1" value="{{ old('buy_qty') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="get_qty">Get qty</label>
                    <input class="form-control" id="get_qty" name="get_qty" type="number" min="1" value="{{ old('get_qty') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="min_subtotal">Min subtotal</label>
                    <input class="form-control" id="min_subtotal" name="min_subtotal" type="number" step="0.01" min="0" value="{{ old('min_subtotal') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="max_uses">Max uses</label>
                    <input class="form-control" id="max_uses" name="max_uses" type="number" min="1" value="{{ old('max_uses') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="starts_at">Starts</label>
                    <input class="form-control" id="starts_at" name="starts_at" type="date" value="{{ old('starts_at') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="ends_at">Ends</label>
                    <input class="form-control" id="ends_at" name="ends_at" type="date" value="{{ old('ends_at') }}">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                @if ($errors->has('code'))
                    <div class="col-12 text-danger small">{{ $errors->first('code') }}</div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        </div>
    @endif

    @if ($perm('sales.coupons.bulk'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Bulk coupon generation</h2>
            <form method="POST" action="{{ route('sales.coupons.bulk-generate') }}" class="row g-2">
                @csrf
                <div class="col-md-1">
                    <label class="form-label" for="bulk_count">Count</label>
                    <input class="form-control" id="bulk_count" name="count" type="number" min="1" max="500" value="{{ old('count', 10) }}" required>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="bulk_prefix">Prefix</label>
                    <input class="form-control" id="bulk_prefix" name="prefix" value="{{ old('prefix') }}" maxlength="24">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="bulk_type">Type</label>
                    <select class="form-select" id="bulk_type" name="type" required>
                        @foreach (['percent_off', 'fixed_off', 'free_shipping', 'buy_x_get_y'] as $t)
                            <option value="{{ $t }}" @selected(old('type', 'percent_off') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="bulk_value">Value</label>
                    <input class="form-control" id="bulk_value" name="value" type="number" step="0.01" min="0" value="{{ old('value', 10) }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="bulk_buy_qty">Buy qty</label>
                    <input class="form-control" id="bulk_buy_qty" name="buy_qty" type="number" min="1" value="{{ old('buy_qty') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="bulk_get_qty">Get qty</label>
                    <input class="form-control" id="bulk_get_qty" name="get_qty" type="number" min="1" value="{{ old('get_qty') }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="bulk_max_uses">Max uses</label>
                    <input class="form-control" id="bulk_max_uses" name="max_uses" type="number" min="1" value="{{ old('max_uses', 1) }}">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="bulk_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="bulk_active">Active</label>
                    </div>
                </div>
                @if ($errors->has('code'))
                    <div class="col-12 text-danger small">{{ $errors->first('code') }}</div>
                @endif
                <div class="col-12">
                    <button class="btn btn-outline-primary" type="submit">Generate batch</button>
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Coupon code">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="type">Type</label>
                <select class="form-select" id="type" name="type">
                    <option value="">All</option>
                    @foreach (['percent_off', 'fixed_off', 'free_shipping', 'buy_x_get_y'] as $t)
                        <option value="{{ $t }}" @selected($type === $t)>{{ $t }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Type</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Min subtotal</th>
                        <th>Window</th>
                        <th class="text-end">Used</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($coupons as $coupon)
                        <tr>
                            <td><code>{{ $coupon->code }}</code></td>
                            <td>{{ $coupon->type }}</td>
                            <td class="text-end">
                                @if ($coupon->type === 'percent_off')
                                    {{ rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.') }}%
                                @elseif ($coupon->type === 'fixed_off')
                                    {{ number_format((float) $coupon->value, 2) }}
                                @elseif ($coupon->type === 'buy_x_get_y')
                                    Buy {{ $coupon->buy_qty }} get {{ $coupon->get_qty }}
                                    @if ((float) $coupon->value < 100)
                                        ({{ rtrim(rtrim(number_format((float) $coupon->value, 2), '0'), '.') }}% off free)
                                    @endif
                                @else
                                    —
                                @endif
                            </td>
                            <td class="text-end">{{ $coupon->min_subtotal !== null ? number_format((float) $coupon->min_subtotal, 2) : '—' }}</td>
                            <td class="small">
                                {{ $coupon->starts_at?->toDateString() ?? '∞' }}
                                →
                                {{ $coupon->ends_at?->toDateString() ?? '∞' }}
                            </td>
                            <td class="text-end">
                                {{ $coupon->used_count }}{{ $coupon->max_uses !== null ? ' / '.$coupon->max_uses : '' }}
                            </td>
                            <td>
                                <span class="erp-status {{ $coupon->is_active ? 'erp-status-active' : 'erp-status-inactive' }}">
                                    {{ $coupon->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No coupons yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $coupons->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
