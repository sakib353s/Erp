@extends('layouts.app')

@section('page_title', 'Purchase returns')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Purchase returns & debit notes"
        subtitle="Goods going back to the supplier, and the credit that comes with them. A posted receipt and a posted bill are both immutable — this is the correction path, and it posts stock out and the debit note to the ledger together."
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.bills.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index') }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Bills
                </a>
            @endif
            @if ($perm('purchase.receipts.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Goods receipts
                </a>
            @endif
            @if ($perm('purchase.returns.create'))
                <a class="btn btn-primary" href="{{ route('purchase.returns.create') }}">
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Raise a return
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Returned this month" value="৳ {{ number_format($summary['returned_month'], 2) }}" icon="bi-arrow-return-left"
                  :hint="$summary['returns_month'].' approved return(s)'" />
        <x-ui.kpi label="Waiting approval" :value="number_format($summary['awaiting'])" icon="bi-hourglass-split"
                  hint="Approval is what moves stock and posts the debit note" />
        <x-ui.kpi label="Drafts" value="৳ {{ number_format($summary['draft'], 2) }}" icon="bi-pencil-square"
                  hint="Not yet claimed from the supplier" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('purchase.returns.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Return number, reason or supplier…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                @foreach (['open' => 'Open (draft or waiting)', 'pending_approval' => 'Waiting approval', 'draft' => 'Draft', 'approved' => 'Approved & posted', 'cancelled' => 'Cancelled'] as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="supplier">Supplier</label>
            <select class="form-select" id="supplier" name="supplier">
                <option value="">All suppliers</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}" @selected($filters['supplier'] === $supplier->id)>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('purchase.returns.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$returns->total().' returns'">
        <thead>
            <tr>
                <th>Return</th>
                <th>Supplier</th>
                <th>Returned on</th>
                <th>Reason</th>
                <th>Against</th>
                <th class="erp-th-num">Lines</th>
                <th class="erp-th-num">Value</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($returns as $return)
                <tr>
                    <td data-label="Return">
                        <a class="erp-cell-strong" href="{{ route('purchase.returns.show', $return) }}">{{ $return->code }}</a>
                    </td>
                    <td data-label="Supplier">{{ $return->supplier?->name ?? 'Removed supplier' }}</td>
                    <td data-label="Returned on">{{ $return->return_date?->format('d M Y') }}</td>
                    <td data-label="Reason" class="erp-td-muted">{{ $return->reasonLabel() }}</td>
                    <td data-label="Against" class="erp-td-muted">
                        @if ($return->receipt)
                            <a href="{{ route('purchase.receipts.show', $return->receipt) }}">{{ $return->receipt->code }}</a>
                        @elseif ($return->bill)
                            <a href="{{ route('purchase.bills.show', $return->bill) }}">{{ $return->bill->code }}</a>
                        @else
                            {{ $return->order?->code ?? 'Direct' }}
                        @endif
                    </td>
                    <td data-label="Lines" class="erp-td-num">{{ $return->lines_count }}</td>
                    <td data-label="Value" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $return->total, 2) }}</td>
                    <td data-label="Status"><x-ui.status :value="$return->status" /></td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-arrow-return-left" title="No purchase returns match this filter"
                                    text="A return is how damaged, wrong or excess goods go back — and how the debit note against the supplier gets created." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $returns->links() }}</div>

    <x-ui.related-pages />
@endsection
