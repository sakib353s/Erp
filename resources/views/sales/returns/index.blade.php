@extends('layouts.app')

@section('page_title', 'Sales Returns')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Sales Returns</h1>
            <p class="erp-page-sub">Request → receive → credit note → refund. History is never rewritten.</p>
        </div>
    </div>

    <div class="erp-card mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="q">Search</label>
                <input class="form-control" id="q" name="q" value="{{ $q }}" placeholder="Return number">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="status">Status</label>
                <select class="form-select" id="status" name="status">
                    <option value="">All</option>
                    @foreach (\App\Domain\Returns\SalesReturn::STATUSES as $s)
                        <option value="{{ $s }}" @selected($status === $s)>{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-secondary w-100" type="submit">Filter</button>
            </div>
        </form>
    </div>

    @if ($perm('returns.create'))
        <div class="erp-card mb-3">
            <h2 class="erp-h3 mb-3">Create return request</h2>
            <form method="POST" action="{{ route('sales.returns.store') }}">
                @csrf
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label" for="invoice_id">Invoice ID</label>
                        <input class="form-control" id="invoice_id" name="invoice_id" type="number" min="1" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="return_reason_id">Reason ID</label>
                        <input class="form-control" id="return_reason_id" name="return_reason_id" type="number" min="1">
                    </div>
                    <div class="col-md-5">
                        <label class="form-label" for="notes">Notes</label>
                        <input class="form-control" id="notes" name="notes" maxlength="500">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="line_invoice_line_id">Invoice line ID</label>
                        <input class="form-control" id="line_invoice_line_id" name="lines[0][invoice_line_id]" type="number" min="1" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="line_qty">Qty</label>
                        <input class="form-control" id="line_qty" name="lines[0][qty]" type="number" step="0.0001" min="0.0001" required>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button class="btn btn-primary w-100" type="submit">Request return</button>
                    </div>
                </div>
            </form>
        </div>
    @endif

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Return #</th>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Status</th>
                        <th class="text-end">Total</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($returns as $salesReturn)
                        <tr>
                            <td><code>{{ $salesReturn->return_no }}</code></td>
                            <td>{{ optional($salesReturn->return_date)->toDateString() }}</td>
                            <td>{{ $salesReturn->invoice?->invoice_no ?? '—' }}</td>
                            <td><span class="erp-status erp-status-active">{{ $salesReturn->status }}</span></td>
                            <td class="text-end">{{ number_format((float) $salesReturn->grand_total, 2) }}</td>
                            <td class="text-end">
                                @if ($perm('returns.receive') && in_array($salesReturn->status, ['requested', 'approved'], true))
                                    <form method="POST" action="{{ route('sales.returns.receive', $salesReturn) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-primary" type="submit">Receive</button>
                                    </form>
                                @endif
                                @if ($perm('returns.credit') && in_array($salesReturn->status, ['received', 'inspected', 'requested', 'approved'], true) && $salesReturn->credit_note_id === null)
                                    <form method="POST" action="{{ route('sales.returns.credit', $salesReturn) }}" class="d-inline">
                                        @csrf
                                        <button class="btn btn-sm btn-outline-secondary" type="submit">Credit note</button>
                                    </form>
                                @endif
                                @if ($perm('returns.refunds.create') && in_array($salesReturn->status, ['credited'], true))
                                    <form method="POST" action="{{ route('sales.returns.refund', $salesReturn) }}" class="d-inline">
                                        @csrf
                                        <input type="hidden" name="idempotency_key" value="refund-{{ $salesReturn->id }}">
                                        <button class="btn btn-sm btn-outline-danger" type="submit">Refund</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">No returns yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $returns->links() }}</div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
