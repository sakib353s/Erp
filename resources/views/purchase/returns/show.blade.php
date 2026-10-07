@extends('layouts.app')

@section('page_title', $return->code.' · purchase return')

@section('content')
    @php($products = $return->lines->filter(fn ($line) => $line->product !== null && $line->product->is_stocked))

    <x-ui.page-header
        eyebrow="Purchase · Supplier returns"
        :title="$return->code"
        :subtitle="'Returned to '.($return->supplier?->name ?? 'the supplier').' on '.$return->return_date?->format('d M Y').' — '.$return->reasonLabel().'.'"
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('purchase.returns.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Returns
            </a>
            @if ($return->isDraft() && $perm('purchase.returns.create'))
                <form method="POST" action="{{ route('purchase.returns.submit', $return) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-send" aria-hidden="true"></i> Submit for approval
                    </button>
                </form>
            @endif
            @if (in_array($return->status, ['draft', 'pending_approval'], true) && $perm('purchase.returns.approve'))
                <form method="POST" action="{{ route('purchase.returns.approve', $return) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"
                            @if ((int) $return->created_by === (int) auth()->id()) disabled title="You raised this return — somebody else must approve it" @endif>
                        <i class="bi bi-check2-circle" aria-hidden="true"></i> Approve & post
                    </button>
                </form>
            @endif
            @if ($return->receipt && $perm('purchase.receipts.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.receipts.show', $return->receipt) }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Receipt {{ $return->receipt->code }}
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($return->status === 'draft')
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-pencil-square" aria-hidden="true"></i>
            <div>
                <strong>Draft.</strong> Nothing has moved yet — approving is what posts the debit note, so until then the supplier
                is not holding a claim from us. Approval takes stock out
                @if ($products->isNotEmpty())
                    ({{ $products->count() }} stock-managed line(s))
                @endif
                and posts Dr accounts payable / Cr inventory (or purchases) with the input tax reversed.
            </div>
        </div>
    @elseif ($return->status === 'pending_approval')
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div><strong>Waiting for approval.</strong> Two pairs of eyes on goods leaving the company — the person who raised it cannot approve it.</div>
        </div>
    @elseif ($return->status === 'approved')
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>
                <strong>Approved and posted.</strong> The stock is out and the ledger carries the debit note
                @if ($return->journal_entry_id)
                    (entry #{{ $return->journal_entry_id }})
                @endif
                @if ($return->bill)
                    — bill <a href="{{ route('purchase.bills.show', $return->bill) }}">{{ $return->bill->code }}</a> has been credited.
                @endif
            </div>
        </div>
    @elseif ($return->status === 'cancelled')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-x-octagon" aria-hidden="true"></i>
            <div><strong>Cancelled.</strong> {{ $return->cancel_reason }}</div>
        </div>
    @endif

    @error('return')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-split">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">The document</h2>
                <div class="erp-card-actions"><x-ui.status :value="$return->status" /></div>
            </div>
            <dl class="erp-dl erp-dl-tight px-3 pb-3">
                <dt>Supplier</dt>
                <dd>{{ $return->supplier?->name ?? 'Removed supplier' }}</dd>
                <dt>Return date</dt>
                <dd>{{ $return->return_date?->format('d M Y') }}</dd>
                <dt>Reason</dt>
                <dd>{{ $return->reasonLabel() }}<span class="d-block erp-td-muted">{{ $return->reason }}</span></dd>
                <dt>Against</dt>
                <dd>
                    @if ($return->receipt)
                        <a href="{{ route('purchase.receipts.show', $return->receipt) }}">{{ $return->receipt->code }}</a>
                    @elseif ($return->bill)
                        <a href="{{ route('purchase.bills.show', $return->bill) }}">{{ $return->bill->code }}</a>
                    @else
                        {{ $return->order?->code ?? 'No source document' }}
                    @endif
                </dd>
                <dt>Goods leave from</dt>
                <dd>{{ $return->warehouse?->name ?? '—' }}</dd>
                <dt>Goods dispatched</dt>
                <dd>{{ $return->goods_dispatched ? 'Yes — handed back to the supplier' : 'Not yet — waiting for collection' }}</dd>
                <dt>Raised by</dt>
                <dd>{{ $return->creator?->name ?? 'System' }}</dd>
                @if ($return->approver)
                    <dt>Approved by</dt>
                    <dd>{{ $return->approver->name }} · {{ $return->approved_at?->format('d M Y H:i') }}</dd>
                @endif
            </dl>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">The debit note</h2>
            </div>
            <dl class="erp-dl px-3 pb-3">
                <dt>Net value</dt>
                <dd>৳ {{ number_format((float) $return->subtotal - (float) $return->discount_total, 2) }}</dd>
                <dt>Discount</dt>
                <dd>৳ {{ number_format((float) $return->discount_total, 2) }}</dd>
                <dt>Input tax reversed</dt>
                <dd>৳ {{ number_format((float) $return->tax_total, 2) }}</dd>
                <dt>Total claimed</dt>
                <dd class="erp-cell-strong">৳ {{ number_format((float) $return->total, 2) }}</dd>
                @if ($return->bill)
                    <dt>Bill outstanding</dt>
                    <dd>
                        ৳ {{ number_format((float) ($credit ?? 0), 2) }}
                        <span class="d-block erp-td-muted">
                            @if ($return->isPosted())
                                After this credit — ৳ {{ number_format((float) $return->bill->credited_amount, 2) }}
                                credited of ৳ {{ number_format((float) $return->bill->total, 2) }}
                            @else
                                Credited so far ৳ {{ number_format((float) $return->bill->credited_amount, 2) }}
                                of ৳ {{ number_format((float) $return->bill->total, 2) }} — approving this return would claim
                                ৳ {{ number_format((float) $return->total, 2) }} of it
                            @endif
                        </span>
                    </dd>
                @endif
                @if ($return->journal_entry_id)
                    <dt>Journal entry</dt>
                    <dd>#{{ $return->journal_entry_id }}</dd>
                @endif
            </dl>
        </div>
    </div>

    <x-ui.table-shell title="Goods going back" :count="$return->lines->count().' lines'">
        <thead>
            <tr>
                <th>Item</th>
                <th class="erp-th-num">Qty</th>
                <th class="erp-th-num">Unit cost</th>
                <th class="erp-th-num">Discount</th>
                <th class="erp-th-num">Tax %</th>
                <th class="erp-th-num">Line value</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($return->lines as $line)
                <tr>
                    <td data-label="Item">
                        <span class="erp-cell-strong">{{ $line->product?->name ?? $line->description }}</span>
                        @if ($line->product)
                            <span class="d-block erp-td-muted">{{ $line->product->sku }}</span>
                        @else
                            <span class="d-block erp-td-muted">Not a stock item — no movement posted</span>
                        @endif
                    </td>
                    <td data-label="Qty" class="erp-td-num">{{ number_format((float) $line->qty, 4) }}</td>
                    <td data-label="Unit cost" class="erp-td-num">৳ {{ number_format((float) $line->unit_cost, 2) }}</td>
                    <td data-label="Discount" class="erp-td-num">৳ {{ number_format((float) $line->discount, 2) }}</td>
                    <td data-label="Tax %" class="erp-td-num">{{ number_format((float) $line->tax_rate, 2) }}%</td>
                    <td data-label="Line value" class="erp-td-num erp-cell-strong">৳ {{ number_format($line->amount(), 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="erp-table-opening">
                <th colspan="5" class="text-end">Total claimed from {{ $return->supplier?->name }}</th>
                <th class="erp-th-num">৳ {{ number_format((float) $return->total, 2) }}</th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    @if (in_array($return->status, ['draft', 'pending_approval'], true) && $perm('purchase.returns.cancel'))
        <div class="erp-card mt-3">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Cancel this return</h2>
            </div>
            <form class="erp-inline-form px-3 pb-3" method="POST" action="{{ route('purchase.returns.cancel', $return) }}">
                @csrf
                <label class="form-label" for="cancel_reason">Why is it being cancelled?</label>
                <div class="d-flex gap-2">
                    <input class="form-control" id="cancel_reason" name="cancel_reason" maxlength="500" required
                           placeholder="Goods were accepted after all / wrong document raised">
                    <button class="btn btn-outline-danger" type="submit">Cancel the return</button>
                </div>
                <div class="erp-help mt-1">
                    A cancelled return leaves no trace in stock or the ledger — it is only possible while nothing has been posted,
                    because a posted correction has to be corrected by another document.
                </div>
            </form>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
