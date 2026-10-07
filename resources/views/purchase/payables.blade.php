@extends('layouts.app')

@section('page_title', 'Payables ageing')

@section('content')
    <x-ui.page-header
        eyebrow="Purchase"
        title="Payables ageing"
        subtitle="What we owe, grouped by how late it is. Every bucket is derived from each bill's own due date — a bill with no due date is never called late."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.ledger.index') }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Supplier ledger
            </a>
            @if ($perm('purchase.bills.view'))
                <a class="btn btn-outline-secondary" href="{{ route('purchase.bills.index', ['status' => 'open']) }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Open bills
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Total payable" value="৳ {{ number_format($ageing['grand'], 2) }}" icon="bi-cash-stack"
                  :hint="$ageing['rows'] ? count($ageing['rows']).' supplier account(s)' : 'Nothing outstanding'" />
        <x-ui.kpi label="Not yet due" value="৳ {{ number_format($ageing['totals']['current'], 2) }}" icon="bi-calendar-check"
                  hint="Within the agreed terms" />
        <x-ui.kpi label="Past due"
                  value="৳ {{ number_format($ageing['grand'] - $ageing['totals']['current'], 2) }}"
                  icon="bi-exclamation-triangle" hint="Late against the bill's own due date" />
        <x-ui.kpi label="90+ days late" value="৳ {{ number_format($ageing['totals']['d90_plus'], 2) }}" icon="bi-shield-exclamation"
                  hint="The part that needs a decision, not a reminder" />
    </div>

    <div class="erp-filterbar">
        <div class="erp-filter">
            <span class="form-label">Show</span>
            <div class="d-flex flex-wrap gap-1">
                <a class="btn btn-sm {{ $bucket === 'all' ? 'btn-primary' : 'btn-outline-secondary' }}" href="{{ route('purchase.payables') }}">All</a>
                @foreach ($labels as $key => $label)
                    <a class="btn btn-sm {{ $bucket === $key ? 'btn-primary' : 'btn-outline-secondary' }}"
                       href="{{ route('purchase.payables', ['bucket' => $key]) }}">
                        {{ $label }}
                        <span class="erp-td-muted">· ৳ {{ number_format($ageing['totals'][$key], 2) }}</span>
                    </a>
                @endforeach
            </div>
        </div>
    </div>

    @if ($focus)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-funnel" aria-hidden="true"></i>
            <div>Showing only suppliers with bills in <strong>{{ $focus }}</strong>. <a href="{{ route('purchase.payables') }}">Show everything</a></div>
        </div>
    @endif

    <x-ui.table-shell :count="count($ageing['rows']).' suppliers'">
        <thead>
            <tr>
                <th>Supplier</th>
                <th class="erp-th-num">Not due</th>
                <th class="erp-th-num">1–30</th>
                <th class="erp-th-num">31–60</th>
                <th class="erp-th-num">61–90</th>
                <th class="erp-th-num">90+</th>
                <th class="erp-th-num">Total owed</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($ageing['rows'] as $row)
                @php($show = $bucket === 'all' || $row['buckets'][$bucket] > 0)
                @continue (! $show)
                <tr>
                    <td data-label="Supplier">
                        <a class="erp-cell-strong" href="{{ route('suppliers.ledger', $row['supplier']) }}">{{ $row['supplier']->name }}</a>
                        <span class="d-block erp-td-muted">
                            {{ $row['bills'] }} open bill(s)
                            @if ($row['oldest_due']) · oldest due {{ $row['oldest_due']->format('d M Y') }}@endif
                            @if ($row['supplier']->is_blacklisted) · blacklisted @endif
                        </span>
                    </td>
                    @foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as $key)
                        <td data-label="{{ $labels[$key] }}" class="erp-td-num {{ $key === 'd90_plus' && $row['buckets'][$key] > 0 ? 'erp-cell-strong' : '' }}">
                            {{ $row['buckets'][$key] > 0 ? number_format($row['buckets'][$key], 2) : '—' }}
                        </td>
                    @endforeach
                    <td data-label="Total owed" class="erp-td-num erp-cell-strong">৳ {{ number_format($row['total'], 2) }}</td>
                    <td data-label="" class="erp-td-actions">
                        <div class="d-flex gap-1 justify-content-end">
                            @if ($perm('purchase.bills.view'))
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('purchase.bills.index', ['supplier' => $row['supplier']->id, 'status' => 'open']) }}">
                                    Bills
                                </a>
                            @endif
                            @if ($perm('purchase.payments.create'))
                                <a class="btn btn-sm btn-primary" href="{{ route('purchase.payments.create') }}">
                                    <i class="bi bi-cash-coin" aria-hidden="true"></i> Pay
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-cash-stack" title="Nothing is outstanding"
                                    text="Every approved bill is settled or credited. Ageing appears here the moment a bill is approved with a balance." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if (count($ageing['rows']) > 0)
            <tfoot>
                <tr class="erp-table-opening">
                    <th>Total</th>
                    @foreach (['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'] as $key)
                        <th class="erp-th-num">{{ number_format($ageing['totals'][$key], 2) }}</th>
                    @endforeach
                    <th class="erp-th-num">{{ number_format($ageing['grand'], 2) }}</th>
                    <th></th>
                </tr>
            </tfoot>
        @endif
    </x-ui.table-shell>

    <div class="erp-help mt-2">
        Ageing is bucketed by each bill's due date, which comes from the supplier's payment terms unless the bill says
        otherwise. A bill whose due date has not arrived sits in "Not due" whatever its age.
    </div>

    <x-ui.related-pages />
@endsection
