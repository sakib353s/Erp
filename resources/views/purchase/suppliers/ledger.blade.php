@extends('layouts.app')

@section('page_title', $supplier->name.' · ledger')

@section('content')
    <x-ui.page-header
        eyebrow="Suppliers · account"
        :title="$supplier->name"
        subtitle="The running account for this supplier: posted bills increase what we owe, payments settle it, approved returns take it back. One row per document, with the balance after it."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.show', $supplier) }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> Profile
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.ledger.index') }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> All accounts
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.statement', array_filter(['from' => $range['from'], 'to' => $range['to']])) }}"
               target="_blank" rel="noopener">
                <i class="bi bi-printer" aria-hidden="true"></i> Statement
            </a>
            @if ($perm('purchase.payments.create') && $payables['due'] > 0)
                <a class="btn btn-primary" href="{{ route('purchase.payments.create') }}">
                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Record a payment
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('suppliers.ledger', $supplier) }}">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $range['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $range['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            <a class="btn btn-link" href="{{ route('suppliers.ledger', $supplier) }}">This month</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        </div>
    </form>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Opening balance" value="৳ {{ number_format($ledger['opening'], 2) }}" icon="bi-hourglass-start"
                  hint="Owed at the start of the period" />
        <x-ui.kpi label="Billed in period" value="৳ {{ number_format($ledger['totals']['credit'], 2) }}" icon="bi-receipt" hint="Posted bills" />
        <x-ui.kpi label="Settled in period" value="৳ {{ number_format($ledger['totals']['debit'], 2) }}" icon="bi-cash-coin"
                  hint="Payments and returns together" />
        <x-ui.kpi label="Closing balance" value="৳ {{ number_format($ledger['closing'], 2) }}" icon="bi-hourglass-end"
                  :hint="$ledger['closing'] > 0 ? 'Owed to this supplier' : 'Nothing owed — we are level or ahead'" />
    </div>

    @if ($payables['overdue'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong>৳ {{ number_format($payables['overdue'], 2) }} of this account is past its due date.</strong>
                Bills are counted late from their own due date — an undated bill is never aged.
            </div>
        </div>
    @endif

    <x-ui.table-shell :count="count($ledger['lines']).' entries'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Particulars</th>
                <th class="erp-th-num">Settled (Dr)</th>
                <th class="erp-th-num">Billed (Cr)</th>
                <th class="erp-th-num">Balance owed</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td data-label="Date">{{ $range['from'] }}</td>
                <td data-label="Reference">—</td>
                <td data-label="Particulars"><span class="erp-cell-strong">Opening balance</span></td>
                <td class="erp-td-num">—</td>
                <td class="erp-td-num">—</td>
                <td data-label="Balance owed" class="erp-td-num erp-cell-strong">৳ {{ number_format($ledger['opening'], 2) }}</td>
            </tr>
            @foreach ($ledger['lines'] as $line)
                <tr>
                    <td data-label="Date">{{ $line['date'] }}</td>
                    <td data-label="Reference"><span class="erp-cell-strong">{{ $line['reference'] }}</span></td>
                    <td data-label="Particulars" class="erp-td-muted">{{ $line['description'] }}</td>
                    <td data-label="Settled (Dr)" class="erp-td-num">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '—' }}</td>
                    <td data-label="Billed (Cr)" class="erp-td-num">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '—' }}</td>
                    <td data-label="Balance owed" class="erp-td-num">৳ {{ number_format($line['balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="erp-table-opening">
                <th colspan="3" class="text-end">Closing balance</th>
                <th class="erp-th-num">৳ {{ number_format($ledger['totals']['debit'], 2) }}</th>
                <th class="erp-th-num">৳ {{ number_format($ledger['totals']['credit'], 2) }}</th>
                <th class="erp-th-num">৳ {{ number_format($ledger['closing'], 2) }}</th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <div class="erp-split mt-3">
        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Ageing of the open bills</h2>
            </div>
            <ul class="erp-list px-3 pb-3">
                @foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as $bucket)
                    @php($label = ['current' => 'Not yet due', 'd1_30' => '1–30 days late', 'd31_60' => '31–60 days late', 'd61_90' => '61–90 days late', 'd90_plus' => '90+ days late'][$bucket] ?? $bucket)
                    <li class="erp-list-row">
                        <div class="erp-list-row-main">{{ $label }}</div>
                        <div class="erp-cell-strong">
                            ৳ {{ number_format($payables['buckets'][$bucket]['amount'] ?? 0, 2) }}
                            <span class="erp-td-muted">· {{ $payables['buckets'][$bucket]['count'] ?? 0 }} bill(s)</span>
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>

        <div class="erp-card">
            <div class="erp-card-head">
                <h2 class="erp-card-title">Open bills</h2>
                <div class="erp-card-actions">
                    @if ($perm('purchase.bills.view'))
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.bills.index', ['supplier' => $supplier->id, 'status' => 'open']) }}">
                            See them all
                        </a>
                    @endif
                </div>
            </div>
            <ul class="erp-list px-3 pb-3">
                @forelse ($payables['rows'] as $bill)
                    <li class="erp-list-row">
                        <div class="erp-list-row-main">
                            <a href="{{ route('purchase.bills.show', $bill) }}">{{ $bill->code }}</a>
                            <span class="d-block erp-td-muted">
                                {{ $bill->bill_date?->format('d M Y') }}
                                @if ($bill->due_date) · due {{ $bill->due_date->format('d M Y') }}@endif
                                @if ($bill->daysOverdue() > 0) · {{ $bill->daysOverdue() }} day(s) late @endif
                            </span>
                        </div>
                        <div class="erp-cell-strong">৳ {{ number_format((float) $bill->due_amount, 2) }}</div>
                    </li>
                @empty
                    <li class="erp-list-row"><div class="erp-list-row-main erp-td-muted">Nothing open — every bill is settled.</div></li>
                @endforelse
            </ul>
        </div>
    </div>

    <x-ui.related-pages />
@endsection
