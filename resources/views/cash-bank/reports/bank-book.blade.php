@extends('layouts.app')

@section('page_title', 'Bank book')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash Reports · Bank book"
        title="Every bank and wallet, side by side"
        subtitle="Opening, in, out and closing for each of the company's own accounts, over one window — the page a treasurer reads on a Monday morning. Every figure is a posted journal line, so the column added up is the money the company actually has."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-cash-stack" aria-hidden="true"></i> Cash in hand
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash.reports.flow', request()->query()) }}">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cash flow
            </a>
            <a class="btn btn-primary" href="{{ route('cash.reports.bank-book', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Accounts" :value="$result['rows']->count()" icon="bi-bank"
                  hint="Banks and wallets this company holds money in" />
        <x-ui.kpi label="Opened with" value="৳ {{ number_format((float) $result['totals']['opening'], 2) }}" icon="bi-box-arrow-in-right"
                  hint="Everything posted before this window" />
        <x-ui.kpi label="Moved in the window"
                  :value="'৳ '.number_format((float) $result['totals']['in'] - (float) $result['totals']['out'], 2)"
                  icon="bi-arrow-left-right"
                  :hint="'In ৳ '.number_format((float) $result['totals']['in'], 2).' · out ৳ '.number_format((float) $result['totals']['out'], 2)" />
        <x-ui.kpi label="Closing" value="৳ {{ number_format((float) $result['totals']['closing'], 2) }}" icon="bi-safe"
                  hint="What the ledger says the banks and wallets hold" />
    </div>

    @include('cash-bank.reports.partials.filters', [
        'action' => route('cash.reports.bank-book'),
        'branches' => $branches,
    ])

    <x-ui.table-shell title="The accounts" :count="$result['rows']->count().' account(s)'">
        <thead>
            <tr>
                <th>Account</th>
                <th>Kind</th>
                <th class="erp-th-num">Opening</th>
                <th class="erp-th-num">In</th>
                <th class="erp-th-num">Out</th>
                <th class="erp-th-num">Closing</th>
                <th class="erp-th-num">Movements</th>
                <th>Last movement</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['rows'] as $row)
                <tr>
                    <td>
                        <a class="erp-cell-strong" href="{{ route('cash.reports.book', ['account' => $row['account']->id, 'from' => $filters['from'], 'to' => $filters['to']]) }}">
                            {{ $row['account']->name }}
                        </a>
                        <div class="erp-td-muted">{{ $row['account']->code }}</div>
                    </td>
                    <td>
                        <span class="erp-status erp-status-{{ $row['instrument'] === 'bank' ? 'active' : 'cleared' }}">{{ $row['label'] }}</span>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $row['opening'], 2) }}</td>
                    <td class="erp-td-num erp-money-in">৳ {{ number_format((float) $row['in'], 2) }}</td>
                    <td class="erp-td-num erp-money-out">৳ {{ number_format((float) $row['out'], 2) }}</td>
                    <td class="erp-td-num"><span class="erp-cell-strong">৳ {{ number_format((float) $row['closing'], 2) }}</span></td>
                    <td class="erp-td-num">{{ $row['rows'] }}</td>
                    <td class="erp-td-muted">{{ $row['last_movement'] ?? 'never moved' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-bank" title="No bank or wallet account yet"
                                    text="An account is declared on the money desk; until there is one, there is nothing here to read." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="2">Added up</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['opening'], 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['in'], 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['out'], 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['closing'], 2) }}</th>
                <th class="erp-th-num" colspan="2"></th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <p class="erp-filter-note mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        A closing figure here is the account's ledger balance on the last day of the window — the same number the account's own book and the trial balance carry.
        It is not the bank's figure; the bank's is what the reconciliation desk proves it against.
    </p>

    <x-ui.related-pages />
@endsection
