@extends('layouts.guest')

@section('page_title', 'Verify '.$document['invoice_no'])

@section('content')
    {{-- §16-20: what a holder of the paper sees. Everything on this page is on
         the invoice itself; nothing else is read, and the reader is told that
         rather than left to wonder what was hidden. --}}
    @php
        $paymentLabels = ['paid' => 'Paid in full', 'partial' => 'Part paid', 'unpaid' => 'Unpaid'];
        $statusKey = $document['status'] === 'void' ? 'void' : $document['payment_state'];
    @endphp

    <div class="d-flex flex-wrap align-items-start justify-content-between gap-3">
        <div>
            <p class="erp-eyebrow mb-1">Document verification</p>
            <h1 class="erp-h1 mb-1">{{ $document['title'] }} <code>{{ $document['invoice_no'] }}</code></h1>
            <p class="erp-page-sub mb-0">
                {{ $document['company']['name'] ?? 'This company' }}
                @if ($document['branch'])
                    · {{ $document['branch'] }}
                @endif
                · dated {{ $document['invoice_date'] ?? '—' }}
            </p>
        </div>
        <span class="erp-status erp-status-{{ $statusKey }}">
            {{ $document['status'] === 'void' ? 'Cancelled invoice' : $paymentLabels[$document['payment_state']] }}
        </span>
    </div>

    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-patch-check" aria-hidden="true"></i>
        <div>
            <strong>This invoice is on our books.</strong>
            <p class="mb-0">
                The number above was issued by {{ $document['company']['name'] ?? 'this company' }}
                and matches the record held on this system.
                @if ($document['raised_by'])
                    Raised by {{ $document['raised_by'] }}.
                @endif
                Checked at {{ $document['checked_at']->format('d M Y, H:i') }}.
            </p>
        </div>
    </div>

    @if ($document['status'] === 'void')
        <div class="erp-note erp-note-danger mt-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div>
                <strong>This invoice has been cancelled.</strong>
                <p class="mb-0">The document exists and its number was issued, but it does not stand as a demand for payment. Ask the issuer for the replacement document before paying anything against this number.</p>
            </div>
        </div>
    @endif

    <div class="erp-card mt-3">
        <h2 class="erp-h3 mb-3">What was bought</h2>
        <div class="table-responsive">
            <table class="table erp-table mb-0">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Item</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit</th>
                        <th class="text-end">Total</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($document['lines'] as $line)
                        <tr>
                            <td>{{ $line['line_no'] }}</td>
                            <td>
                                <span class="erp-cell-strong">{{ $line['name'] }}</span>
                                @if ($line['warranty_noted'])
                                    <div class="erp-td-muted">Warranty noted on this line</div>
                                @endif
                            </td>
                            <td class="text-end">{{ $line['qty'] }}</td>
                            <td class="text-end">{{ number_format($line['unit_price'], 2) }}</td>
                            <td class="text-end">{{ number_format($line['line_total'], 2) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-ui.empty icon="bi-list-ul" title="No lines are recorded on this invoice"
                                            text="A document with no lines is not a demand for payment — treat this as a record that needs checking with the issuer." />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <dl class="erp-dl erp-dl-tight mb-0">
                    <div>
                        <dt>Subtotal</dt>
                        <dd>{{ number_format($document['totals']['subtotal'], 2) }} {{ $document['currency'] }}</dd>
                    </div>
                    @if ($document['totals']['tax_applicable'] || $document['totals']['tax'] > 0)
                        <div>
                            <dt>VAT{{ $document['totals']['tax_code'] ? ' ('.$document['totals']['tax_code'].')' : '' }}</dt>
                            <dd>{{ number_format($document['totals']['tax'], 2) }} {{ $document['currency'] }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>Total</dt>
                        <dd><strong>{{ number_format($document['totals']['grand_total'], 2) }} {{ $document['currency'] }}</strong></dd>
                    </div>
                </dl>
            </div>
            <div class="col-md-6">
                <dl class="erp-dl erp-dl-tight mb-0">
                    <div>
                        <dt>Paid</dt>
                        <dd>{{ number_format($document['totals']['paid'], 2) }} {{ $document['currency'] }}</dd>
                    </div>
                    <div>
                        <dt>Due</dt>
                        <dd><strong>{{ number_format($document['totals']['due'], 2) }} {{ $document['currency'] }}</strong></dd>
                    </div>
                    @if ($document['due_date'])
                        <div>
                            <dt>Payment due</dt>
                            <dd>{{ $document['due_date'] }}</dd>
                        </div>
                    @endif
                    <div>
                        <dt>State</dt>
                        <dd>{{ ucfirst(str_replace('_', ' ', $document['issue_state'])) }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </div>

    <div class="erp-card mt-3">
        <h2 class="erp-h3 mb-3">Who issued it</h2>
        <dl class="erp-dl erp-dl-tight mb-0">
            <div>
                <dt>Company</dt>
                <dd>{{ $document['company']['name'] ?? '—' }}</dd>
            </div>
            @if ($document['company']['address'] ?? null)
                <div>
                    <dt>Address</dt>
                    <dd>{{ $document['company']['address'] }}</dd>
                </div>
            @endif
            @if ($document['company']['phone'] ?? null)
                <div>
                    <dt>Phone</dt>
                    <dd>{{ $document['company']['phone'] }}</dd>
                </div>
            @endif
            @if ($document['company']['tin'] ?? null)
                <div>
                    <dt>TIN</dt>
                    <dd>{{ $document['company']['tin'] }}</dd>
                </div>
            @endif
            @if ($document['company']['bin'] ?? null)
                <div>
                    <dt>BIN</dt>
                    <dd>{{ $document['company']['bin'] }}</dd>
                </div>
            @endif
            @if ($document['customer'])
                <div>
                    <dt>Billed to</dt>
                    <dd>
                        {{ $document['customer']['name'] }}
                        @if ($document['customer']['phone'])
                            <span class="erp-td-muted">{{ $document['customer']['phone'] }}</span>
                        @endif
                    </dd>
                </div>
            @endif
        </dl>
    </div>

    @if ($document['warranty_note'])
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
            <div>
                <strong>About the warranty</strong>
                <p class="mb-0">{{ $document['warranty_note'] }}</p>
            </div>
        </div>
    @endif

    <div class="erp-note mt-3">
        <i class="bi bi-eye-slash" aria-hidden="true"></i>
        <div>
            <strong>What this page does not show</strong>
            <p class="mb-0">{{ $document['privacy_note'] }}</p>
        </div>
    </div>
@endsection
