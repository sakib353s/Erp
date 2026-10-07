@extends('layouts.app')

@section('page_title', 'Purchase bills')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Purchase bills"
        subtitle="A goods receipt moves the stock; a bill is what turns a delivery into money owed. Approving a bill posts the payable to the ledger — Dr inventory (or purchases) and input tax, Cr accounts payable."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.index') }}">
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Goods receipts
            </a>
            @if ($perm('purchase.orders.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.orders.index') }}">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Orders
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('purchase.payables') }}">
                <i class="bi bi-alarm" aria-hidden="true"></i> Ageing
            </a>
            @if ($perm('purchase.returns.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.returns.index') }}">
                    <i class="bi bi-arrow-return-left" aria-hidden="true"></i> Returns
                </a>
            @endif
            @if ($perm('purchase.payments.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.payments.index') }}">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Payments
                </a>
            @endif
            @if ($perm('purchase.bills.create'))
                <a class="btn btn-primary" href="{{ route('purchase.bills.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Enter a bill
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Amount payable" value="৳ {{ number_format($summary['payable'], 2) }}" icon="bi-cash-stack"
                  :hint="$summary['open_bills'].' open bill(s)'" />
        <x-ui.kpi label="Overdue" value="৳ {{ number_format($summary['overdue'], 2) }}" icon="bi-exclamation-triangle"
                  :hint="$summary['overdue_bills'].' bill(s) past their due date'" />
        <x-ui.kpi label="Due within 7 days" value="৳ {{ number_format($summary['due_week'], 2) }}" icon="bi-calendar-week"
                  hint="Plan the payment run around this" />
        <x-ui.kpi label="Waiting approval" :value="number_format($summary['awaiting'])" icon="bi-hourglass-split"
                  :hint="$summary['drafts'].' draft or pending in total'" />
    </div>

    @if ($summary['overdue_bills'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>৳ {{ number_format($summary['overdue'], 2) }} is past due on {{ $summary['overdue_bills'] }} bill(s).</strong>
                Ageing follows the supplier's own terms — a bill with no due date is never counted as late.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('purchase.bills.index') }}" role="search">
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <div class="erp-input-group">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                       placeholder="Bill code, the supplier's bill number or supplier name…" autocomplete="off">
            </div>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">Any status</option>
                <option value="open" @selected($filters['status'] === 'open')>Open payable</option>
                <option value="overdue" @selected($filters['status'] === 'overdue')>Overdue</option>
                <option value="draft" @selected($filters['status'] === 'draft')>Draft</option>
                <option value="pending_approval" @selected($filters['status'] === 'pending_approval')>Waiting approval</option>
                <option value="approved" @selected($filters['status'] === 'approved')>Approved</option>
                <option value="paid" @selected($filters['status'] === 'paid')>Paid</option>
                <option value="cancelled" @selected($filters['status'] === 'cancelled')>Cancelled</option>
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
            <label class="form-label" for="from">Bill date from</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="sort">Sort</label>
            <select class="form-select" id="sort" name="sort">
                <option value="">Newest first</option>
                <option value="due" @selected($filters['sort'] === 'due')>Due date (oldest first)</option>
                <option value="value" @selected($filters['sort'] === 'value')>Largest value</option>
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters))
                <a class="btn btn-link" href="{{ route('purchase.bills.index') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell :count="$bills->total().' bills'">
        <thead>
            <tr>
                <th>Bill</th>
                <th>Supplier</th>
                <th>Against</th>
                <th>Bill date</th>
                <th>Due</th>
                <th class="erp-th-num">Total</th>
                <th class="erp-th-num">Still owed</th>
                <th>Status</th>
                <th class="erp-th-actions">Open</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($bills as $bill)
                <tr>
                    <td data-label="Bill">
                        <a class="erp-row-link" href="{{ route('purchase.bills.show', $bill) }}">{{ $bill->code }}</a>
                        @if ($bill->supplier_bill_no)
                            <span class="erp-td-muted d-block small">their ref {{ $bill->supplier_bill_no }}</span>
                        @endif
                    </td>
                    <td data-label="Supplier">{{ $bill->supplier?->name ?? 'Removed supplier' }}</td>
                    <td data-label="Against" class="erp-td-muted">
                        @if ($bill->receipt)
                            <a href="{{ route('purchase.receipts.show', $bill->receipt) }}">{{ $bill->receipt->code }}</a>
                        @elseif ($bill->order)
                            <a href="{{ route('purchase.orders.show', $bill->order) }}">{{ $bill->order->code }}</a>
                        @else
                            Direct bill
                        @endif
                    </td>
                    <td data-label="Bill date">{{ $bill->bill_date?->format('d M Y') }}</td>
                    <td data-label="Due">
                        @if ($bill->due_date)
                            {{ $bill->due_date->format('d M Y') }}
                            @php($late = $bill->daysOverdue())
                            @if ($late > 0)
                                <span class="erp-td-muted d-block small">{{ $late }} day(s) late</span>
                            @endif
                        @else
                            <span class="erp-td-muted">On demand</span>
                        @endif
                    </td>
                    <td data-label="Total" class="erp-td-num">৳ {{ number_format((float) $bill->total, 2) }}</td>
                    <td data-label="Still owed" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $bill->due_amount, 2) }}</td>
                    <td data-label="Status"><x-ui.status :value="$bill->status" /></td>
                    <td data-label="Open" class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.bills.show', $bill) }}">Open</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="9">
                        <x-ui.empty icon="bi-receipt" title="No bills match this filter"
                                    text="A bill can be entered straight from a posted goods receipt, or as a direct bill for services." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">{{ $bills->links() }}</div>

    <x-ui.related-pages />
@endsection
