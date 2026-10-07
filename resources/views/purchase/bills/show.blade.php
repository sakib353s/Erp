@extends('layouts.app')

@section('page_title', $bill->code)

@section('content')
    <x-ui.page-header
        eyebrow="Purchase · Purchase bill"
        :title="$bill->code"
        :subtitle="($bill->supplier?->name ?? 'Removed supplier').($bill->supplier_bill_no ? ' · their bill '.$bill->supplier_bill_no : '').' · dated '.$bill->bill_date?->format('d M Y')"
        :pin="true">
        <x-slot:actions>
            @if ($perm('purchase.bills.create') && $bill->isDraft())
                <form method="POST" action="{{ route('purchase.bills.submit', $bill) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">
                        <i class="bi bi-send" aria-hidden="true"></i> Submit for approval
                    </button>
                </form>
            @endif
            @if ($perm('purchase.bills.approve') && in_array($bill->status, ['draft', 'pending_approval'], true))
                <form method="POST" action="{{ route('purchase.bills.approve', $bill) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve &amp; post the payable
                    </button>
                </form>
            @endif
            @if ($perm('purchase.payments.create') && $bill->isPosted() && (float) $bill->due_amount > 0)
                <a class="btn btn-primary" href="{{ route('purchase.payments.create', ['bill' => $bill->id]) }}">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Record payment
                </a>
            @endif
            @if ($bill->receipt)
                <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.show', $bill->receipt) }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> {{ $bill->receipt->code }}
                </a>
            @endif
            <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All bills
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('bill')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror
    @error('cancel_reason')
        <div class="erp-note erp-note-danger mb-3"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div>{{ $message }}</div></div>
    @enderror

    @if ($bill->status === 'cancelled')
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-slash-circle" aria-hidden="true"></i>
            <div><strong>Cancelled.</strong> {{ $bill->cancel_reason }}</div>
        </div>
    @elseif ($bill->isPosted())
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                <strong>Posted {{ $bill->posted_at?->format('d M Y H:i') }}.</strong>
                ৳ {{ number_format((float) $bill->total, 2) }} was recognised as a payable, with the value landing in
                @if ($bill->receipt) inventory @else purchases &amp; services @endif
                @if ((float) $bill->tax_total > 0) and ৳ {{ number_format((float) $bill->tax_total, 2) }} of input tax @endif.
                The bill is now immutable — a mistake is corrected with a credit note, never by editing history.
            </div>
        </div>
    @elseif ($bill->status === 'pending_approval')
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong>Waiting for approval — nothing is owed yet.</strong>
                Approval is what posts the payable, and whoever entered this bill cannot be the person who approves it.
            </div>
        </div>
    @else
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>Draft — the ledger has not been touched.</strong>
                Until this bill is approved it is paperwork only: no payable, no ageing, no effect on what the company owes.
            </div>
        </div>
    @endif

    @if ($bill->match_state && ! $bill->isMatched())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-shuffle" aria-hidden="true"></i>
            <div>
                <strong>Three-way match: {{ str_replace('_', ' ', $bill->match_state) }}.</strong>
                {{ $bill->match_summary }}
                The bill was still posted — hiding a real liability would be worse than flagging it — and the mismatch stays
                on the record for whoever chases the supplier.
            </div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Bill total" value="৳ {{ number_format((float) $bill->total, 2) }}" icon="bi-receipt"
                  hint="Lines minus discounts plus tax" />
        <x-ui.kpi label="Still owed" value="৳ {{ number_format((float) $bill->due_amount, 2) }}" icon="bi-cash-stack"
                  :hint="$bill->due_date ? 'Due '.$bill->due_date->format('d M Y') : 'Due on demand'" />
        <x-ui.kpi label="Input tax" value="৳ {{ number_format((float) $bill->tax_total, 2) }}" icon="bi-percent"
                  hint="Netted against output VAT on the ledger" />
        <x-ui.kpi label="Match" :value="str_replace('_', ' ', (string) ($bill->match_state ?? 'not checked'))" icon="bi-shuffle"
                  hint="Order · receipt · bill" />
    </div>

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Three-way match</h2>
                @if ($match['state'])
                    <div class="erp-card-actions"><x-ui.status :value="$match['state']" /></div>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th class="erp-th-num">Ordered</th>
                            <th class="erp-th-num">Order price</th>
                            <th class="erp-th-num">Received</th>
                            <th class="erp-th-num">Billed</th>
                            <th class="erp-th-num">Billed at</th>
                            <th>Agrees?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($match['lines'] as $row)
                            <tr>
                                <td data-label="Item">
                                    <span class="erp-cell-strong">{{ $row['label'] }}</span>
                                    @if ($row['sku'])<span class="erp-td-muted d-block small">{{ $row['sku'] }}</span>@endif
                                </td>
                                <td data-label="Ordered" class="erp-td-num">{{ $row['ordered'] === null ? '—' : number_format($row['ordered'], 2) }}</td>
                                <td data-label="Order price" class="erp-td-num">{{ $row['ordered_price'] === null ? '—' : '৳ '.number_format($row['ordered_price'], 2) }}</td>
                                <td data-label="Received" class="erp-td-num">{{ $row['received'] === null ? '—' : number_format($row['received'], 2) }}</td>
                                <td data-label="Billed" class="erp-td-num erp-cell-strong">{{ number_format($row['billed'], 2) }}</td>
                                <td data-label="Billed at" class="erp-td-num">৳ {{ number_format($row['billed_price'], 2) }}</td>
                                <td data-label="Agrees?">
                                    @if ($row['qty_agrees'] === null && $row['price_agrees'] === null)
                                        <span class="erp-td-muted">no source document</span>
                                    @elseif ($row['qty_agrees'] === false)
                                        <x-ui.status value="rejected" label="quantity differs" />
                                    @elseif ($row['price_agrees'] === false)
                                        <x-ui.status value="pending" label="price differs" />
                                    @else
                                        <x-ui.status value="approved" label="agrees" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($match['summary'])
                <div class="erp-help px-3 pb-3">{{ $match['summary'] }}</div>
            @endif
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Document</h2>
                @if ($bill->journal_entry_id && $perm('accounting.journals.view'))
                    <div class="erp-card-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('accounting.journals.show', $bill->journal_entry_id) }}">
                            <i class="bi bi-journal-text" aria-hidden="true"></i> Journal entry
                        </a>
                    </div>
                @endif
            </div>
            <dl class="erp-dl erp-dl-tight px-3">
                <dt>Status</dt>
                <dd><x-ui.status :value="$bill->status" /></dd>
                <dt>Supplier</dt>
                <dd><a href="{{ route('suppliers.show', $bill->supplier) }}">{{ $bill->supplier?->name }}</a></dd>
                <dt>Theirs vs ours</dt>
                <dd>{{ $bill->supplier_bill_no ?: '—' }} <span class="erp-td-muted">· {{ $bill->code }}</span></dd>
                <dt>Delivered by</dt>
                <dd>
                    @if ($bill->receipt)
                        <a href="{{ route('purchase.receipts.show', $bill->receipt) }}">{{ $bill->receipt->code }}</a>
                        <span class="erp-td-muted d-block small">received {{ $bill->receipt->received_date?->format('d M Y') }}</span>
                    @elseif ($bill->order)
                        <a href="{{ route('purchase.orders.show', $bill->order) }}">{{ $bill->order->code }}</a>
                    @else
                        Direct bill — no goods receipt
                    @endif
                </dd>
                <dt>Terms</dt>
                <dd>
                    {{ (int) ($bill->supplier?->payment_terms_days ?? 0) > 0 ? (int) $bill->supplier->payment_terms_days.' days' : 'On demand' }}
                    @if ($bill->due_date)
                        <span class="erp-td-muted d-block small">due {{ $bill->due_date->format('d M Y') }}</span>
                    @endif
                </dd>
                <dt>Paid to date</dt>
                <dd>
                    ৳ {{ number_format((float) $bill->paid_amount, 2) }}
                    @if ((float) $bill->paid_amount > 0)
                        <a class="erp-td-muted d-block small" href="{{ route('purchase.payments.index', ['bill' => $bill->id]) }}">see the payments</a>
                    @endif
                </dd>
                <dt>Subtotal · discount · tax</dt>
                <dd>
                    ৳ {{ number_format((float) $bill->subtotal, 2) }} − {{ number_format((float) $bill->discount_total, 2) }}
                    + {{ number_format((float) $bill->tax_total, 2) }}
                </dd>
                <dt>Entered by</dt>
                <dd>{{ $bill->creator?->name ?? '—' }}</dd>
                <dt>Approved</dt>
                <dd>
                    @if ($bill->approved_at)
                        {{ $bill->approved_at->format('d M Y H:i') }}
                        <span class="erp-td-muted d-block small">{{ $bill->approver?->name }}</span>
                    @else
                        <span class="erp-td-muted">Not approved yet</span>
                    @endif
                </dd>
                @if ($bill->notes)
                    <dt>Notes</dt>
                    <dd>{{ $bill->notes }}</dd>
                @endif
            </dl>

            @if ($perm('purchase.bills.cancel') && ! $bill->isPosted() && $bill->status !== 'cancelled')
                <div class="erp-inline-form mx-3 mb-3">
                    <form method="POST" action="{{ route('purchase.bills.cancel', $bill) }}">
                        @csrf
                        <label class="form-label" for="cancel_reason">Cancel this bill</label>
                        <div class="d-flex gap-2">
                            <input class="form-control" id="cancel_reason" name="cancel_reason" maxlength="500" required
                                   placeholder="Why — recorded in the audit trail">
                            <button class="btn btn-outline-danger" type="submit">Cancel</button>
                        </div>
                        <div class="erp-help mt-1">Once a bill is posted, cancelling an unposted document is no longer a way out — raise a credit note.</div>
                    </form>
                </div>
            @endif
        </div>
    </div>

    <div class="erp-card">
        <div class="erp-card-head">
            <h2 class="erp-card-title">Billed lines</h2>
            <span class="erp-chip erp-chip-outline">{{ $bill->lines->count() }}</span>
        </div>
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="erp-th-num">Qty</th>
                        <th class="erp-th-num">Unit cost</th>
                        <th class="erp-th-num">Discount</th>
                        <th class="erp-th-num">Tax</th>
                        <th class="erp-th-num">Line total</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bill->lines as $line)
                        <tr>
                            <td data-label="Item">
                                <span class="erp-cell-strong">{{ $line->product?->name ?? $line->description }}</span>
                                <span class="erp-td-muted d-block small">{{ $line->product?->sku }}</span>
                            </td>
                            <td data-label="Qty" class="erp-td-num">{{ number_format((float) $line->qty, 4) }}</td>
                            <td data-label="Unit cost" class="erp-td-num">৳ {{ number_format((float) $line->unit_cost, 4) }}</td>
                            <td data-label="Discount" class="erp-td-num">{{ (float) $line->discount > 0 ? '৳ '.number_format((float) $line->discount, 2) : '—' }}</td>
                            <td data-label="Tax" class="erp-td-num">{{ (float) $line->tax_rate > 0 ? (float) $line->tax_rate.'%' : '—' }}</td>
                            <td data-label="Line total" class="erp-td-num erp-cell-strong">৳ {{ number_format((float) $line->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="erp-table-opening">
                        <th colspan="5" class="text-end">Bill total</th>
                        <th class="erp-th-num">৳ {{ number_format((float) $bill->total, 2) }}</th>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
