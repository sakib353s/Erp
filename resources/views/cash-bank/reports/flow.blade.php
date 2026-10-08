@extends('layouts.app')

@section('page_title', 'Cash flow')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash Reports · Cash flow"
        title="Where the money came from, and where it went"
        subtitle="Built from the other side of every posting that touched a money account — the counter-account is what names a movement, so a receipt from a customer is a customer receipt however the clerk filed it. Moving money between two of the company's own accounts is reported apart: that is a change of address, not cash flow."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash.reports.bank-book', request()->query()) }}">
                <i class="bi bi-list-columns" aria-hidden="true"></i> Bank book
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('expenses.reports.index', request()->query()) }}">
                <i class="bi bi-receipt" aria-hidden="true"></i> Expense report
            </a>
            <a class="btn btn-primary" href="{{ route('cash.reports.flow', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Money in" value="৳ {{ number_format((float) $result['totals']['in'], 2) }}" icon="bi-arrow-down-circle"
                  :hint="$result['inflows']->count().' source(s) — sales, receipts, anything that brought money in'" />
        <x-ui.kpi label="Money out" value="৳ {{ number_format((float) $result['totals']['out'], 2) }}" icon="bi-arrow-up-circle"
                  :hint="$result['outflows']->count().' use(s) — what the money was spent on'" />
        <x-ui.kpi label="Net movement" value="৳ {{ number_format((float) $result['totals']['net'], 2) }}"
                  icon="{{ (float) $result['totals']['net'] >= 0 ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow' }}"
                  hint="In minus out, across every cash, bank and wallet account" />
        <x-ui.kpi label="Between our own accounts"
                  value="৳ {{ number_format((float) $result['totals']['transfer_in'], 2) }}"
                  icon="bi-arrow-left-right"
                  hint="Left out of the totals above — nobody outside the company was paid or paid us" />
    </div>

    @include('cash-bank.reports.partials.filters', [
        'action' => route('cash.reports.flow'),
        'branches' => $branches,
    ])

    @if ($result['inflows']->isEmpty() && $result['outflows']->isEmpty() && $result['transfers']->isEmpty())
        <x-ui.table-shell title="The flow">
            <tbody>
                <tr>
                    <td>
                        <x-ui.empty icon="bi-arrow-left-right" title="Nothing moved in this window"
                                    text="Widen the dates, or check the money accounts — this report reads posted journal lines only, so nothing waiting for approval is in it." />
                    </td>
                </tr>
            </tbody>
        </x-ui.table-shell>
    @else
        <div class="erp-split mb-3">
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Money in</h2>
                        <p class="erp-card-sub">Named by the account on the other side of the posting.</p>
                    </div>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-ok">৳ {{ number_format((float) $result['totals']['in'], 2) }}</span>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Came from</th>
                                <th class="erp-th-num">Postings</th>
                                <th class="erp-th-num">Amount</th>
                                <th class="erp-th-num">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($result['inflows'] as $row)
                                <tr>
                                    <td><span class="erp-cell-strong">{{ $row['counter_account'] }}</span>
                                        <div class="erp-td-muted">{{ $row['account_type'] }}</div>
                                    </td>
                                    <td class="erp-td-num">{{ $row['rows'] }}</td>
                                    <td class="erp-td-num erp-money-in">৳ {{ number_format((float) $row['amount'], 2) }}</td>
                                    <td class="erp-td-num">{{ $row['share'] }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="erp-td-muted">Nothing came in during this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Money out</h2>
                        <p class="erp-card-sub">The same reading, on the way out.</p>
                    </div>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-danger">৳ {{ number_format((float) $result['totals']['out'], 2) }}</span>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Went to</th>
                                <th class="erp-th-num">Postings</th>
                                <th class="erp-th-num">Amount</th>
                                <th class="erp-th-num">Share</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($result['outflows'] as $row)
                                <tr>
                                    <td><span class="erp-cell-strong">{{ $row['counter_account'] }}</span>
                                        <div class="erp-td-muted">{{ $row['account_type'] }}</div>
                                    </td>
                                    <td class="erp-td-num">{{ $row['rows'] }}</td>
                                    <td class="erp-td-num erp-money-out">৳ {{ number_format((float) $row['amount'], 2) }}</td>
                                    <td class="erp-td-num">{{ $row['share'] }}%</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="erp-td-muted">Nothing went out during this window.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </section>
        </div>

        @if ($result['by_month'] !== [])
            <x-ui.table-shell title="Month by month" :count="count($result['by_month']).' month(s)'">
                <thead>
                    <tr>
                        <th>Month</th>
                        <th class="erp-th-num">In</th>
                        <th class="erp-th-num">Out</th>
                        <th class="erp-th-num">Net</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($result['by_month'] as $month)
                        <tr>
                            <td><span class="erp-cell-strong">{{ $month['month'] }}</span></td>
                            <td class="erp-th-num erp-money-in">৳ {{ number_format($month['in'], 2) }}</td>
                            <td class="erp-th-num erp-money-out">৳ {{ number_format($month['out'], 2) }}</td>
                            <td class="erp-td-num">{{ number_format($month['net'], 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </x-ui.table-shell>
        @endif

        @if ($result['transfers']->isNotEmpty())
            <section class="erp-card mt-3">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Between our own accounts</h2>
                        <p class="erp-card-sub">
                            A transfer has a money account on both sides, so it is not cash flow — the company is no richer for moving cash from the tin to the bank.
                            It is listed here because leaving it out entirely would make the books look like they moved less money than they did.
                        </p>
                    </div>
                    <div class="erp-card-actions">
                        <span class="erp-chip erp-chip-outline">
                            ৳ {{ number_format((float) $result['totals']['transfer_in'], 2) }} moved
                        </span>
                    </div>
                </header>
                <div class="erp-table-scroll">
                    <table class="erp-table">
                        <thead>
                            <tr>
                                <th>Pair</th>
                                <th class="erp-th-num">Postings</th>
                                <th class="erp-th-num">Moved</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($result['transfers'] as $row)
                                <tr>
                                    <td><span class="erp-cell-strong">{{ $row['counter_account'] }}</span></td>
                                    <td class="erp-td-num">{{ $row['rows'] }}</td>
                                    <td class="erp-td-num">৳ {{ number_format((float) $row['amount'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <th colspan="2">In and out cancel out across the pair</th>
                                <th class="erp-th-num">
                                    ৳ {{ number_format((float) $result['totals']['transfer_in'] + (float) $result['totals']['transfer_out'], 2) }}
                                </th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </section>
        @endif
    @endif

    <p class="erp-filter-note mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Every posting that touched a money account over this window, counted once by its counterpart. Add the inflow and outflow groups together and you get
        exactly the debit and credit totals of the money accounts themselves — which is the only reason a report like this can be trusted.
    </p>

    <x-ui.related-pages />
@endsection
