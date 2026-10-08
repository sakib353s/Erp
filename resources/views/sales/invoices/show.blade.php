@extends('layouts.app')

@section('page_title', ($invoice->printed_title ?? 'INVOICE').' '.$invoice->invoice_no)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">{{ $invoice->printed_title ?? 'INVOICE' }} {{ $invoice->invoice_no }}</h1>
            <p class="erp-page-sub">
                Status: <span class="erp-status erp-status-{{ str_replace('_', '-', strtolower((string) ($invoice->status))) }}">{{ $invoice->status }}</span>
                · {{ optional($invoice->invoice_date)->toDateString() }}
            </p>
        </div>
        <div class="d-flex gap-2">
            @if ($perm('sales.invoices.statutory_print') && $invoice->tax_applicable)
                <a class="btn btn-outline-dark" target="_blank" rel="noopener"
                   href="{{ route('sales.invoices.mushak-91', $invoice) }}">
                    <i class="bi bi-printer" aria-hidden="true"></i> Mushak 9.1
                </a>
            @endif
            @if ($perm('sales.invoices.issue') && in_array($invoice->status, ['draft', 'pending'], true))
                <form method="POST" action="{{ route('sales.invoices.issue', $invoice) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Issue</button>
                </form>
            @endif
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Lines</h2>
                <div class="table-responsive">
                    <table class="table erp-table mb-0">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Product</th>
                                <th class="text-end">Qty</th>
                                <th class="text-end">Unit</th>
                                <th class="text-end">Tax</th>
                                <th class="text-end">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($invoice->lines as $line)
                                <tr>
                                    <td>{{ $line->line_no }}</td>
                                    <td>{{ $line->product?->name ?? $line->description ?? '—' }}</td>
                                    <td class="text-end">{{ $line->qty }}</td>
                                    <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->tax, 2) }}</td>
                                    <td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="erp-card">
                <h2 class="erp-h3 mb-3">Totals</h2>
                <dl class="mb-0">
                    {{-- §15-14: with inclusive pricing the figures shown are the
                         taxable value and the VAT inside the total, not a
                         subtotal the tax is added to. --}}
                    @if ($taxInclusive ?? false)
                        <div class="d-flex justify-content-between"><dt>Taxable value</dt><dd>{{ number_format((float) $invoice->taxable_base, 2) }}</dd></div>
                        <div class="d-flex justify-content-between"><dt>VAT (included)</dt><dd>{{ number_format((float) $invoice->tax, 2) }}</dd></div>
                    @else
                        <div class="d-flex justify-content-between"><dt>Subtotal</dt><dd>{{ number_format((float) $invoice->subtotal, 2) }}</dd></div>
                        <div class="d-flex justify-content-between"><dt>Tax</dt><dd>{{ number_format((float) $invoice->tax, 2) }}</dd></div>
                    @endif
                    <div class="d-flex justify-content-between"><dt>Paid</dt><dd>{{ number_format((float) $invoice->paid_amount, 2) }}</dd></div>
                    <div class="d-flex justify-content-between fw-bold border-top pt-2 mt-2">
                        <dt>Due</dt>
                        <dd>{{ number_format((float) $invoice->due_amount, 2) }}</dd>
                    </div>
                </dl>
            </div>

            @if ($perm('sales.payments.create') && in_array($invoice->status, ['issued', 'partial'], true) && (float) $invoice->due_amount > 0)
                <div class="erp-card mt-3">
                    <h2 class="erp-h3 mb-3">Record payment</h2>
                    <form method="POST" action="{{ route('sales.payments.store') }}">
                        @csrf
                        <input type="hidden" name="invoice_id" value="{{ $invoice->id }}">
                        <div class="mb-2">
                            <label class="form-label" for="amount">Amount</label>
                            <input class="form-control" id="amount" name="amount" type="number" step="0.01" min="0.01"
                                   max="{{ $invoice->due_amount }}" value="{{ $invoice->due_amount }}" required>
                        </div>
                        <div class="mb-2">
                            <label class="form-label" for="method">Method</label>
                            <select class="form-select" id="method" name="method">
                                <option value="cash">Cash</option>
                                <option value="bank">Bank</option>
                                <option value="mobile">Mobile wallet</option>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="reference">Reference</label>
                            <input class="form-control" id="reference" name="reference" maxlength="64">
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Record payment</button>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
