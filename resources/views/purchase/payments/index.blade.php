@extends('layouts.app')

@section('page_title', 'Supplier payments')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Supplier payments"
        subtitle="Money leaving the company against a posted bill: every payment debits accounts payable, credits cash or bank, and settles the bill it names — nothing here is an unallocated float."
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.bills.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index', ['status' => 'open']) }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Open bills
                </a>
            @endif
            @if ($perm('purchase.payments.create'))
                <a class="btn btn-primary" href="{{ route('purchase.payments.create') }}">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Record a payment
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Paid this month" value="৳ {{ number_format($summary['paid_month'], 2) }}" icon="bi-cash-coin"
                  :hint="$summary['payments_month'].' payment(s)'" />
        <x-ui.kpi label="Paid today" value="৳ {{ number_format($summary['paid_today'], 2) }}" icon="bi-calendar-day"
                  hint="Cash actually out of the door today" />
        <x-ui.kpi label="Still payable" value="৳ {{ number_format($summary['payable'], 2) }}" icon="bi-hourglass"
                  :hint="$summary['open_bills'].' open bill(s)'" />
    </div>

    @if ($filters['bill'])
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <div>Showing only the payments allocated to one bill. <a href="{{ route('purchase.payments.index') }}">Show every payment</a></div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('purchase.payments.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Voucher number, bank reference or supplier…" autocomplete="off">
            </div>
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
            <label class="form-label" for="method">Paid by</label>
            <select class="form-select" id="method" name="method">
                <option value="">Any method</option>
                @foreach (['cash', 'bank', 'cheque', 'mobile'] as $method)
                    <option value="{{ $method }}" @selected($filters['method'] === $method)>{{ ucfirst($method) }}</option>
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
                <a class="btn btn-link" href="{{ route('purchase.payments.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$payments->total().' payments'">
        <thead>
            <tr>
                <th>Voucher</th>
                <th>Supplier</th>
                <th>Against</th>
                <th>Paid on</th>
                <th>Method</th>
                <th class="erp-th-num">Amount</th>
                <th>Reference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td data-label="Voucher"><span class="erp-cell-strong">{{ $payment->receipt_no }}</span></td>
                    <td data-label="Supplier">{{ $payment->supplier?->name ?? 'Removed supplier' }}</td>
                    <td data-label="Against" class="erp-td-muted">
                        @php($bills = $payment->allocations->map(fn ($a) => $a->allocatable)->filter())
                        @forelse ($bills as $allocatedBill)
                            @if ($allocatedBill instanceof \App\Domain\Purchase\Models\PurchaseBill)
                                <a href="{{ route('purchase.bills.show', $allocatedBill) }}">{{ $allocatedBill->code }}</a>@unless ($loop->last),&nbsp;@endunless
                            @else
                                {{ class_basename($allocatedBill) }} #{{ $allocatedBill->getKey() }}@unless ($loop->last),&nbsp;@endunless
                            @endif
                        @empty
                            Unallocated
                        @endforelse
                    </td>
                    <td data-label="Paid on">{{ $payment->paid_at?->format('d M Y') }}</td>
                    <td data-label="Method">{{ ucfirst((string) $payment->method) }}</td>
                    <td data-label="Amount" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $payment->amount, 2) }}</td>
                    <td data-label="Reference" class="erp-td-muted">{{ $payment->reference ?: '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-cash-coin" title="No supplier payments match this filter"
                                    text="Payments are recorded against a posted bill, so start from an open bill or from the button above." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $payments->links() }}</div>

    <x-ui.related-pages />
@endsection
