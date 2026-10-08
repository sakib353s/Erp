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
            {{-- §16-23/§16-25: the same invoice through the reusable renderer, and
                 the record of every copy that has come off a printer since. --}}
            @if ($perm('sales.invoices.print'))
                <a class="btn btn-outline-dark" target="_blank" rel="noopener"
                   href="{{ route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id]) }}">
                    <i class="bi bi-printer" aria-hidden="true"></i> Print
                </a>
                <a class="btn btn-outline-dark"
                   href="{{ route('documents.print.show', ['type' => 'invoice', 'id' => $invoice->id, 'action' => 'download']) }}">
                    <i class="bi bi-download" aria-hidden="true"></i> Save copy
                </a>
            @endif
            @if ($perm('documents.view_history'))
                <a class="btn btn-outline-dark"
                   href="{{ route('documents.print.history', ['type' => 'invoice', 'id' => $invoice->id]) }}">
                    <i class="bi bi-clock-history" aria-hidden="true"></i> Print history
                </a>
            @endif
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

    @php
        // §16-19: a draft is not a document yet, so there is nothing to publish
        // until it is issued — and the panel says that instead of offering a
        // button whose link would resolve to nothing.
        $verifiable = in_array($invoice->status, ['issued', 'partial', 'paid', 'void'], true);
        $linkState = $verification['published'] ? 'active' : ($verification['revoked_at'] ? 'cancelled' : 'not_configured');
        $linkLabel = $verification['published'] ? 'Link live' : ($verification['revoked_at'] ? 'Withdrawn' : 'No link');
    @endphp

    <div class="erp-card mt-3">
        <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
            <div>
                <h2 class="erp-h3 mb-1">Public verification link</h2>
                <p class="erp-page-sub mb-0">
                    Somebody holding this invoice can open the number on this system and check that it was issued here.
                    Only this document is published — never the accounting entry, the internal notes or another customer's details.
                </p>
            </div>
            <x-ui.status :value="$linkState" :label="$linkLabel" />
        </div>

        @unless ($verifiable)
            <div class="erp-note mt-3">
                <i class="bi bi-hourglass" aria-hidden="true"></i>
                <div>
                    <strong>This {{ $invoice->status }} document cannot be published.</strong>
                    <p class="mb-0">Issue the invoice first; a customer verifying a draft would be verifying something the company has not asked them to pay.</p>
                </div>
            </div>
        @else
            @if ($verification['published'])
                <div class="row g-3 mt-3">
                    <div class="col-lg-7">
                        <label class="form-label" for="verification-url">The address inside the QR code</label>
                        <input class="form-control" id="verification-url" type="text" readonly value="{{ $verification['url'] }}">
                        <p class="erp-td-muted mb-0">Reprinting this invoice prints the same address. Rotating it prints a new one and stops the old one working.</p>
                    </div>
                    <div class="col-lg-5">
                        <dl class="erp-dl erp-dl-tight mb-0">
                            <div><dt>Published</dt><dd>{{ $verification['issued_at']?->format('d M Y, H:i') ?? '—' }}</dd></div>
                            <div><dt>Rotation</dt><dd>{{ $verification['rotation'] }}</dd></div>
                            <div><dt>Times opened</dt><dd>{{ $verification['visits'] }}</dd></div>
                            <div>
                                <dt>Last opened</dt>
                                <dd>{{ $verification['last_seen_at'] ? \Illuminate\Support\Carbon::parse($verification['last_seen_at'])->format('d M Y, H:i') : 'never' }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>
            @elseif ($verification['revoked_at'])
                <div class="erp-note erp-note-warn mt-3">
                    <i class="bi bi-link-45deg" aria-hidden="true"></i>
                    <div>
                        <strong>Withdrawn {{ $verification['revoked_at']->format('d M Y, H:i') }}.</strong>
                        <p class="mb-0">Any copy of this invoice that carries the old address now resolves to nothing. Publishing again issues a fresh link.</p>
                    </div>
                </div>
            @else
                <div class="erp-note mt-3">
                    <i class="bi bi-qr-code" aria-hidden="true"></i>
                    <div>
                        <strong>Nothing is published yet.</strong>
                        <p class="mb-0">Publishing writes the address into the invoice's QR code and opens the page that verifies it. The link can be rotated or withdrawn at any time.</p>
                    </div>
                </div>
            @endif

            @if ($perm('sales.invoices.issue'))
                <div class="erp-form-actions mt-3">
                    @if ($verification['published'])
                        <a class="btn btn-outline-dark" target="_blank" rel="noopener" href="{{ $verification['url'] }}">
                            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open the page
                        </a>
                        <form method="POST" action="{{ route('sales.invoices.verification.rotate', $invoice) }}"
                              data-confirm="Rotate the link? The address printed on copies already handed out will stop working.">
                            @csrf
                            <button class="btn btn-outline-dark" type="submit">Rotate the link</button>
                        </form>
                        <form method="POST" action="{{ route('sales.invoices.verification.revoke', $invoice) }}"
                              data-confirm="Withdraw the link? Nothing will verify this invoice until a new one is published.">
                            @csrf
                            <button class="btn btn-outline-danger" type="submit">Withdraw</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('sales.invoices.verification.issue', $invoice) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit">Publish verification link</button>
                        </form>
                    @endif
                </div>
            @endif
        @endunless
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
