@extends('layouts.app')

@section('page_title', 'Promotions')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Promotions</h1>
            <p class="erp-page-sub">Windowed, branch-scoped, priority-ranked discounts applied server-side at document create.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('sales.promotions.flash') }}">Flash sales</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.reports.promotions') }}">Promotion reports</a>
            <a class="btn btn-outline-secondary" href="{{ route('sales.coupons.index') }}">Coupons</a>
        </div>
    </div>

    @if ($perm('sales.promotions.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Create promotion</h2>
            <form method="POST" action="{{ route('sales.promotions.store') }}" class="row g-2">
                @csrf
                <div class="col-md-3">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="{{ old('name') }}" required maxlength="120">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="code">Code (optional)</label>
                    <input class="form-control" id="code" name="code" value="{{ old('code') }}" maxlength="48">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="type">Type</label>
                    <select class="form-select" id="type" name="type" required>
                        @foreach ($types as $t)
                            <option value="{{ $t }}" @selected(old('type', 'percent_off') === $t)>{{ $t }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="kind">Kind</label>
                    <select class="form-select" id="kind" name="kind">
                        @foreach ($kinds as $k)
                            <option value="{{ $k }}" @selected(old('kind', 'standard') === $k)>{{ $k }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="value">Value</label>
                    <input class="form-control" id="value" name="value" type="number" step="0.01" min="0" value="{{ old('value', 10) }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="priority">Priority</label>
                    <input class="form-control" id="priority" name="priority" type="number" min="1" max="9999" value="{{ old('priority', 100) }}">
                </div>
                <div class="col-md-1">
                    <label class="form-label" for="min_subtotal">Min subtotal</label>
                    <input class="form-control" id="min_subtotal" name="min_subtotal" type="number" step="0.01" min="0" value="{{ old('min_subtotal') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="starts_at">Starts</label>
                    <input class="form-control" id="starts_at" name="starts_at" type="date" value="{{ old('starts_at') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="ends_at">Ends</label>
                    <input class="form-control" id="ends_at" name="ends_at" type="date" value="{{ old('ends_at') }}">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="branch_id">Branch (blank = all)</label>
                    <select class="form-select" id="branch_id" name="branch_id">
                        <option value="">All branches</option>
                        @foreach (\App\Domain\Foundation\Branch::query()->orderBy('name')->get() as $branch)
                            <option value="{{ $branch->id }}" @selected((string) old('branch_id') === (string) $branch->id)>{{ $branch->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="description">Description</label>
                    <input class="form-control" id="description" name="description" value="{{ old('description') }}" maxlength="500">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" checked>
                        <label class="form-check-label" for="is_active">Active</label>
                    </div>
                </div>
                @if ($errors->has('name'))
                    <div class="col-12 text-danger small">{{ $errors->first('name') }}</div>
                @endif
                <div class="col-12">
                    <button class="btn btn-primary" type="submit">Create</button>
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Name or code">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="kind">Kind</label>
                <select class="form-select" id="kind" name="kind">
                    <option value="">All</option>
                    @foreach ($kinds as $k)
                        <option value="{{ $k }}" @selected($kind === $k)>{{ $k }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-4">
                    <input class="form-check-input" type="checkbox" id="active_now" name="active_now" value="1" @checked($activeNow)>
                    <label class="form-check-label" for="active_now">Active now</label>
                </div>
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
                        <th>Name</th>
                        <th>Code</th>
                        <th>Type / kind</th>
                        <th class="text-end">Value</th>
                        <th class="text-end">Priority</th>
                        <th>Window</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($promotions as $promotion)
                        <tr>
                            <td>{{ $promotion->name }}</td>
                            <td>@if ($promotion->code)<code>{{ $promotion->code }}</code>@else—@endif</td>
                            <td>{{ $promotion->type }} / {{ $promotion->kind }}</td>
                            <td class="text-end">
                                @if ($promotion->type === 'percent_off')
                                    {{ rtrim(rtrim(number_format((float) $promotion->value, 2), '0'), '.') }}%
                                @else
                                    {{ number_format((float) $promotion->value, 2) }}
                                @endif
                            </td>
                            <td class="text-end">{{ $promotion->priority }}</td>
                            <td class="small">
                                {{ $promotion->starts_at?->toDateString() ?? '∞' }}
                                →
                                {{ $promotion->ends_at?->toDateString() ?? '∞' }}
                            </td>
                            <td>
                                <span class="erp-status {{ $promotion->is_active ? 'erp-status-active' : 'erp-status-inactive' }}">
                                    {{ $promotion->is_active ? 'active' : 'inactive' }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No promotions yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $promotions->links() }}</div>
    </div>
@endsection
