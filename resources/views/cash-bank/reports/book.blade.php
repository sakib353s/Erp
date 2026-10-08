@extends('layouts.app')

@section('page_title', 'Cash book')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash Reports · Cash book"
        title="One account's book"
        subtitle="The account as a statement reads: what it opened with, every posted movement in the window with the entry that made it, and the balance after each one. Nothing here is computed twice — this is the general ledger filtered to one account."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                <i class="bi bi-bank" aria-hidden="true"></i> The accounts
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash.reports.bank-book', request()->query()) }}">
                <i class="bi bi-list-columns" aria-hidden="true"></i> Every bank
            </a>
            <a class="btn btn-primary" href="{{ route('cash.reports.book', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Opened with" value="৳ {{ number_format((float) $book['opening'], 2) }}" icon="bi-box-arrow-in-right"
                  hint="Brought forward from everything posted before this window" />
        <x-ui.kpi label="Money in" value="৳ {{ number_format((float) $book['in'], 2) }}" icon="bi-arrow-down-circle"
                  hint="Debits to this account in the window" />
        <x-ui.kpi label="Money out" value="৳ {{ number_format((float) $book['out'], 2) }}" icon="bi-arrow-up-circle"
                  hint="Credits to this account in the window" />
        <x-ui.kpi label="Closing" value="৳ {{ number_format((float) $book['closing'], 2) }}" icon="bi-safe"
                  :hint="'The ledger\'s own figure for '.$book['label']" />
    </div>

    @include('cash-bank.reports.partials.filters', [
        'action' => route('cash.reports.book'),
        'branches' => $branches,
        'extra' => view('cash-bank.reports.partials.account-extra', ['filters' => $filters, 'accounts' => $accounts])->render(),
    ])

    @if ($book['days'] !== [])
        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Day by day</h2>
                    <p class="erp-card-sub">The same movements folded into the days they happened on — the page a drawer is checked against.</p>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th class="erp-th-num">Movements</th>
                            <th class="erp-th-num">In</th>
                            <th class="erp-th-num">Out</th>
                            <th class="erp-th-num">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($book['days'] as $day)
                            <tr>
                                <td><span class="erp-cell-strong">{{ $day['date'] }}</span></td>
                                <td class="erp-td-num">{{ $day['rows'] }}</td>
                                <td class="erp-td-num erp-money-in">৳ {{ number_format($day['in'], 2) }}</td>
                                <td class="erp-td-num erp-money-out">৳ {{ number_format($day['out'], 2) }}</td>
                                <td class="erp-td-num">{{ number_format($day['in'] - $day['out'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <x-ui.table-shell title="The book" :count="$book['label']">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>Particulars</th>
                <th class="erp-th-num">Debit</th>
                <th class="erp-th-num">Credit</th>
                <th class="erp-th-num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($book['rows'] as $row)
                <tr @class(['erp-row-muted' => ! empty($row['is_opening'])])>
                    <td>{{ $row['entry_date'] ?? '—' }}</td>
                    <td class="erp-td-muted font-monospace">{{ $row['entry_no'] ?? '—' }}</td>
                    <td>
                        <span class="erp-cell-strong">{{ $row['description'] ?: '—' }}</span>
                        @if (! empty($row['is_opening']))
                            <span class="erp-chip erp-chip-outline">brought forward</span>
                        @endif
                    </td>
                    <td class="erp-td-num erp-money-in">{{ (float) $row['debit'] > 0 ? number_format((float) $row['debit'], 2) : '' }}</td>
                    <td class="erp-td-num erp-money-out">{{ (float) $row['credit'] > 0 ? number_format((float) $row['credit'], 2) : '' }}</td>
                    <td class="erp-td-num">{{ number_format((float) $row['running_balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Added up</th>
                <th class="erp-th-num erp-money-in">{{ number_format((float) $book['rows'][count($book['rows']) - 1]['debit'] ?? 0, 2) }}</th>
                <th class="erp-th-num erp-money-out">{{ number_format((float) $book['rows'][count($book['rows']) - 1]['credit'] ?? 0, 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $book['closing'], 2) }}</th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <p class="erp-filter-note mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Opening and closing here are the ledger's: the closing figure is the account's own balance, not the last row added up by hand. A bank's statement will
        disagree until the cheques it has not cleared are accounted for — that is what the reconciliation desk proves.
    </p>

    <x-ui.related-pages />
@endsection
