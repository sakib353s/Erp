@extends('layouts.app')

@section('page_title', 'Invoices')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Invoices</h1>
            <p class="erp-page-sub">Printed title: INVOICE (D10). GL posts only on issue.</p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-sm {{ $overdue ? 'btn-danger' : 'btn-outline-danger' }}"
               href="{{ route('sales.invoices.index', array_filter(['overdue' => 1, 'q' => $q ?: null, 'status' => $status ?: null])) }}">
                Overdue only
            </a>
            @if ($overdue)
                <a class="btn btn-sm btn-outline-secondary" href="{{ route('sales.invoices.index', array_filter(['q' => $q ?: null, 'status' => $status ?: null])) }}">
                    Clear overdue filter
                </a>
            @endif
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            @if ($overdue)
                <input type="hidden" name="overdue" value="1">
            @endif
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Invoice number">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (\App\Domain\Sales\Invoice::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="overdue" name="overdue" @checked($overdue)>
                    <label class="form-check-label" for="overdue">Overdue only</label>
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
                        <th>Invoice #</th>
                        <th>Date</th>
                        <th>Due date</th>
                        <th>Customer</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Due</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($invoices as $invoice)
                        @php
                            $open = (float) $invoice->due_amount > 0;
                            $days = $open ? $invoiceQuery->daysOverdue($invoice) : 0;
                        @endphp
                        <tr @class(['table-danger' => $days > 0])>
                            <td>
                                <a class="fw-semibold text-decoration-none" href="{{ route('sales.invoices.show', $invoice) }}">
                                    {{ $invoice->invoice_no }}
                                </a>
                            </td>
                            <td>{{ optional($invoice->invoice_date)->toDateString() }}</td>
                            <td>
                                {{ optional($invoice->due_date)->toDateString() ?? '—' }}
                                @if ($days > 0)
                                    <span class="badge text-bg-danger">{{ $days }}d overdue</span>
                                @endif
                            </td>
                            <td>{{ $invoice->customer?->name ?? '—' }}</td>
                            <td><span class="erp-status erp-status-active">{{ $invoice->status }}</span></td>
                            <td class="text-end">{{ number_format((float) $invoice->grand_total, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $invoice->due_amount, 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                {{ $overdue ? 'No overdue invoices.' : 'No invoices yet.' }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $invoices->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
