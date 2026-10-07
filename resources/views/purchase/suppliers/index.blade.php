@extends('layouts.app')

@section('page_title', 'Suppliers')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Suppliers"
        subtitle="Everyone you buy from, with what is still owed in goods and what has actually been received. A blacklisted supplier cannot receive a new document."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Purchase orders
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Goods receipts
            </a>
            @if ($perm('suppliers.create'))
                <a class="btn btn-primary" href="{{ route('suppliers.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> New supplier
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Active suppliers" :value="number_format($summary['suppliers'])" icon="bi-truck" hint="Records that can receive documents" />
        <x-ui.kpi label="Open orders" :value="number_format($summary['open_orders'])" icon="bi-clipboard-check" hint="Approved, still short of goods" />
        <x-ui.kpi label="Open order value" value="৳ {{ number_format($summary['open_value'], 2) }}" icon="bi-cash-stack" hint="Committed but not received" />
        <x-ui.kpi label="Awaiting approval" :value="number_format($summary['awaiting_approval'])" icon="bi-hourglass-split" hint="Submitted, not yet approved" />
        <x-ui.kpi label="Received this month" value="৳ {{ number_format($summary['receipts_month_value'], 2) }}" icon="bi-box-arrow-in-down" :hint="$summary['receipts_month'].' posted receipt(s)'" />
        <x-ui.kpi label="Blacklisted" :value="number_format($summary['blacklisted'])" icon="bi-slash-circle" hint="Blocked with a recorded reason" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('suppliers.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Name, code, phone or BIN…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">All categories</option>
                @foreach ($categories as $category)
                    <option value="{{ $category }}" @selected($filters['category'] === $category)>{{ ucfirst($category) }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                <option value="orderable" @selected($filters['status'] === 'orderable')>Orderable</option>
                <option value="inactive" @selected($filters['status'] === 'inactive')>Inactive</option>
                <option value="blacklisted" @selected($filters['status'] === 'blacklisted')>Blacklisted</option>
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <option value="">Name (A→Z)</option>
                <option value="code" @selected($filters['sort'] === 'code')>Supplier code</option>
                <option value="newest" @selected($filters['sort'] === 'newest')>Newest first</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('suppliers.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$suppliers->total().' suppliers'">
        <thead>
            <tr>
                <th>Supplier</th>
                <th>Contact</th>
                <th>Category</th>
                <th>Terms</th>
                <th class="erp-th-num">Orders</th>
                <th class="erp-th-num">Receipts</th>
                <th>Status</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($suppliers as $supplier)
                <tr>
                    <td data-label="Supplier">
                        <a class="erp-row-link" href="{{ route('suppliers.show', $supplier) }}">{{ $supplier->name }}</a>
                        <span class="erp-td-muted d-block small">{{ $supplier->code }}
                            @if ($supplier->district) · {{ $supplier->district->name }}@endif</span>
                    </td>
                    <td data-label="Contact" class="erp-td-muted">
                        {{ $supplier->contact_person ?? '—' }}
                        <span class="d-block small">{{ $supplier->phone ?? $supplier->email ?? '' }}</span>
                    </td>
                    <td data-label="Category" class="erp-td-muted">{{ $supplier->category ? ucfirst($supplier->category) : '—' }}</td>
                    <td data-label="Terms" class="erp-td-muted">
                        {{ $supplier->payment_terms_days > 0 ? $supplier->payment_terms_days.' days' : 'Cash' }}
                    </td>
                    <td data-label="Orders" class="erp-td-num">{{ $supplier->orders_count }}</td>
                    <td data-label="Receipts" class="erp-td-num">{{ $supplier->receipts_count }}</td>
                    <td data-label="Status"><x-ui.status :value="$supplier->status()" /></td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('suppliers.show', $supplier) }}">Profile</a>
                        @if ($perm('purchase.orders.create') && ! $supplier->is_blacklisted && $supplier->is_active)
                            <a class="btn btn-sm btn-light" href="{{ route('purchase.orders.create', ['supplier' => $supplier->id]) }}">New P.O.</a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-truck" title="No suppliers match this filter"
                                    text="Add the first supplier before raising a purchase order." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $suppliers->links() }}</div>

    <x-ui.related-pages />
@endsection
