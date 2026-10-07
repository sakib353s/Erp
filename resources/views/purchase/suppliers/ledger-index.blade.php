@extends('layouts.app')

@section('page_title', 'Supplier ledger')

@section('content')
    <x-ui.page-header
        eyebrow="Suppliers"
        title="Supplier ledger"
        subtitle="Every supplier we have done money with, and the balance their documents leave: bills increase what we owe, payments and returns take it back. Orders and receipts are deliberately absent — they are not money."
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.bills.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.payables') }}">
                    <i class="bi bi-alarm" aria-hidden="true"></i> Ageing
                </a>
            @endif
            @if ($perm('purchase.payments.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.payments.index') }}">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Payments
                </a>
            @endif
            <a class="btn btn-primary" href="{{ route('suppliers.index') }}">
                <i class="bi bi-truck" aria-hidden="true"></i> All suppliers
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Billed (posted)" value="৳ {{ number_format($totals['billed'], 2) }}" icon="bi-receipt" hint="Approved and posted bills only" />
        <x-ui.kpi label="Paid" value="৳ {{ number_format($totals['paid'], 2) }}" icon="bi-cash-coin" hint="Money actually sent" />
        <x-ui.kpi label="Credited by returns" value="৳ {{ number_format($totals['credited'], 2) }}" icon="bi-arrow-return-left" hint="Debit notes taken back" />
        <x-ui.kpi label="Still owed" value="৳ {{ number_format($totals['outstanding'], 2) }}" icon="bi-hourglass" hint="Open bills' outstanding balance" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('suppliers.ledger.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Find a supplier</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $search }}" placeholder="Name or code…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filterbar-actions">
            @if ($search !== '')
                <a class="btn btn-link" href="{{ route('suppliers.ledger.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$rows->count().' accounts'">
        <thead>
            <tr>
                <th>Supplier</th>
                <th class="erp-th-num">Billed</th>
                <th class="erp-th-num">Paid</th>
                <th class="erp-th-num">Credited</th>
                <th class="erp-th-num">Still owed</th>
                <th class="erp-th-num">Documents</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td data-label="Supplier">
                        <a class="erp-cell-strong" href="{{ route('suppliers.show', $row['supplier']) }}">{{ $row['supplier']->name }}</a>
                        <span class="d-block erp-td-muted">
                            {{ $row['supplier']->code }}
                            @if ($row['supplier']->is_blacklisted) · blacklisted @endif
                            @if ((int) $row['supplier']->payment_terms_days > 0) · {{ (int) $row['supplier']->payment_terms_days }} day terms @endif
                        </span>
                    </td>
                    <td data-label="Billed" class="erp-td-num">৳ {{ number_format($row['billed'], 2) }}</td>
                    <td data-label="Paid" class="erp-td-num">৳ {{ number_format($row['paid'], 2) }}</td>
                    <td data-label="Credited" class="erp-td-num">৳ {{ number_format($row['credited'], 2) }}</td>
                    <td data-label="Still owed" class="erp-td-num erp-cell-strong">৳ {{ number_format($row['outstanding'], 2) }}</td>
                    <td data-label="Documents" class="erp-td-muted">
                        {{ $row['bills'] }} bill(s) · {{ $row['payments'] }} payment(s)@if ($row['returns'] > 0) · {{ $row['returns'] }} return(s)@endif
                    </td>
                    <td data-label="" class="erp-td-actions">
                        <div class="d-flex gap-1 justify-content-end">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('suppliers.ledger', $row['supplier']) }}">
                                <i class="bi bi-journal-text" aria-hidden="true"></i> Ledger
                            </a>
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('suppliers.statement', $row['supplier']) }}" target="_blank" rel="noopener">
                                <i class="bi bi-printer" aria-hidden="true"></i> Statement
                            </a>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-journal-text" title="{{ $search !== '' ? 'No supplier matches that search' : 'No supplier account has moved yet' }}"
                                    text="A supplier appears here as soon as a bill is approved, a payment is recorded or a return is posted." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if ($rows->isNotEmpty())
            <tfoot>
                <tr class="erp-table-opening">
                    <th>Totals</th>
                    <th class="erp-th-num">৳ {{ number_format($totals['billed'], 2) }}</th>
                    <th class="erp-th-num">৳ {{ number_format($totals['paid'], 2) }}</th>
                    <th class="erp-th-num">৳ {{ number_format($totals['credited'], 2) }}</th>
                    <th class="erp-th-num">৳ {{ number_format($totals['outstanding'], 2) }}</th>
                    <th colspan="2"></th>
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        "Still owed" is the outstanding balance of open bills, so it always equals what the ageing screen shows as
        payable. The account starts with the first document recorded here — suppliers have no opening-balance field yet.
    </div>

    <x-ui.related-pages />
@endsection
