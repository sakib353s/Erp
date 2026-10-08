@extends('layouts.app')

@section('page_title', 'Expense report')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses · Expense Reports"
        title="What the company spent"
        subtitle="Every figure here is a posted expense — a bill waiting for a signature is not in these totals, because it is not in the ledger yet. That is the test this report is built to pass: pick a category, look up the account it points at, and the ledger says the same number."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">
                <i class="bi bi-receipt" aria-hidden="true"></i> The register
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash.reports.flow', request()->query()) }}">
                <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Cash flow
            </a>
            <a class="btn btn-primary" href="{{ route('expenses.reports.index', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Posted in this window"
            value="৳ {{ number_format($report['totals']['amount'], 2) }}"
            icon="bi-cash-stack"
            :hint="$report['totals']['rows'].' expense(s) between '.$report['from'].' and '.$report['to']" />
        <x-ui.kpi
            label="Paid out of an account"
            value="৳ {{ number_format($report['totals']['money'], 2) }}"
            icon="bi-box-arrow-up"
            :hint="'The rest was owed — ৳ '.number_format($report['totals']['payable'], 2).' posted to payables'" />
        <x-ui.kpi
            label="Where it goes"
            value="{{ $report['by_category'][0]['category'] ?? '—' }}"
            icon="bi-diagram-3"
            :hint="$report['by_category'] !== []
                ? '৳ '.number_format($report['by_category'][0]['amount'], 2).' — '.$report['by_category'][0]['share'].'% of the window'
                : 'Nothing posted in this window yet'" />
        <x-ui.kpi
            label="Ledger check"
            value="{{ $report['ledger']['agrees'] ? 'Agrees' : '৳ '.number_format($report['ledger']['difference'], 2) }}"
            icon="{{ $report['ledger']['agrees'] ? 'bi-check2-circle' : 'bi-exclamation-triangle' }}"
            hint="Posted expenses against the debit of every account the categories point at" />
    </div>

    @if (! $report['ledger']['agrees'])
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">The ledger carries ৳ {{ number_format($report['ledger']['ledger'], 2) }} where this report counted ৳ {{ number_format($report['ledger']['reported'], 2) }}</strong>
                The difference is ৳ {{ number_format($report['ledger']['difference'], 2) }}. That is not a rounding artefact — something posted to one of these accounts
                that is not an expense on this register: a manual journal, or a credit line correcting one. The table at the bottom names which account it is on.
            </div>
        </div>
    @endif

    @include('cash-bank.reports.partials.filters', [
        'action' => route('expenses.reports.index'),
        'branches' => $report['branches'],
        'extra' => view('cash-bank.reports.partials.expense-extra', ['filters' => $filters, 'categories' => $report['categories']])->render(),
    ])

    <div class="erp-split mb-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">By category</h2>
                    <p class="erp-card-sub">A category <em>is</em> a ledger account, so this column can be walked straight to the general ledger.</p>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Account</th>
                            <th class="erp-th-num">Expenses</th>
                            <th class="erp-th-num">Amount</th>
                            <th class="erp-th-num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['by_category'] as $row)
                            <tr>
                                <td><span class="erp-cell-strong">{{ $row['category'] }}</span></td>
                                <td class="erp-td-muted">{{ $row['account'] }}</td>
                                <td class="erp-td-num">{{ $row['rows'] }}</td>
                                <td class="erp-td-num">৳ {{ number_format($row['amount'], 2) }}</td>
                                <td class="erp-td-num">{{ $row['share'] }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5">
                                    <x-ui.empty icon="bi-diagram-3" title="Nothing posted in this window"
                                                text="Widen the dates, or check whether the expenses are still waiting for approval — a pending expense is not in the ledger and so it is not here." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">By month</h2>
                    <p class="erp-card-sub">The same money, read down the calendar — which is how a budget is compared.</p>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Month</th>
                            <th class="erp-th-num">Expenses</th>
                            <th class="erp-th-num">Amount</th>
                            <th class="erp-th-num">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($report['by_month'] as $row)
                            <tr>
                                <td><span class="erp-cell-strong">{{ $row['month'] }}</span></td>
                                <td class="erp-td-num">{{ $row['rows'] }}</td>
                                <td class="erp-td-num">৳ {{ number_format($row['amount'], 2) }}</td>
                                <td class="erp-td-num">{{ $row['share'] }}%</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="erp-td-muted">No posted expenses in this window.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <x-ui.table-shell title="The expenses behind the figures" :count="$report['rows']->count().' expense(s)'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Expense</th>
                <th>Category</th>
                <th>Branch</th>
                <th>Payee</th>
                <th>Paid or owed</th>
                <th class="erp-th-num">Amount</th>
                <th>Entry</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($report['rows'] as $row)
                <tr>
                    <td>{{ $row['expense_date'] }}</td>
                    <td>
                        <span class="erp-cell-strong font-monospace">{{ $row['expense_no'] }}</span>
                        @if ($row['is_generated'])
                            <span class="erp-chip erp-chip-outline">from a schedule</span>
                        @endif
                        @if ($row['narration'])
                            <div class="erp-td-muted">{{ $row['narration'] }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $row['category'] }}
                        <div class="erp-td-muted">{{ $row['account_code'] }} — {{ $row['account_name'] }}</div>
                    </td>
                    <td class="erp-td-muted">{{ $row['branch'] ?? 'Company-wide' }}</td>
                    <td>{{ $row['payee'] }}</td>
                    <td>
                        {{ $row['settled_label'] }}
                        @if ($row['money_account'])
                            <div class="erp-td-muted">{{ $row['money_account'] }}</div>
                        @endif
                    </td>
                    <td class="erp-td-num">৳ {{ number_format($row['amount'], 2) }}</td>
                    <td class="erp-td-muted font-monospace">{{ $row['entry_no'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-receipt" title="No expense was posted in this window"
                                    text="The register may hold expenses that are still waiting for approval — those are listed below the ledger check, not in these totals." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Does the ledger agree?</h2>
                <p class="erp-card-sub">
                    The debit carried by every account the categories above point at, against what this report counted. An expense posts a debit; a credit on one of
                    these accounts is something else — a reversal, a supplier credit, a hand-written journal — and it is why the two can differ.
                </p>
            </div>
        </header>
        <div class="erp-table-scroll">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Account</th>
                        <th>Category</th>
                        <th class="erp-th-num">Reported</th>
                        <th class="erp-th-num">Ledger debit</th>
                        <th class="erp-th-num">Ledger credit</th>
                        <th class="erp-th-num">Difference</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($report['ledger']['accounts'] as $row)
                        <tr>
                            <td><span class="erp-cell-strong">{{ $row['account'] }}</span></td>
                            <td class="erp-td-muted">{{ $row['category'] }}</td>
                            <td class="erp-td-num">৳ {{ number_format($row['reported'], 2) }}</td>
                            <td class="erp-td-num">৳ {{ number_format($row['debit'], 2) }}</td>
                            <td class="erp-td-num">৳ {{ number_format($row['credit'], 2) }}</td>
                            <td class="erp-td-num">
                                @if (abs($row['difference']) < 0.005)
                                    <span class="erp-status erp-status-posted">agrees</span>
                                @else
                                    <span class="erp-status erp-status-overdue">৳ {{ number_format($row['difference'], 2) }}</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="erp-td-muted">No category of the filter points at an account with movement in this window.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Added up</th>
                        <th class="erp-th-num">৳ {{ number_format($report['ledger']['reported'], 2) }}</th>
                        <th class="erp-th-num" colspan="2"></th>
                        <th class="erp-th-num">
                            @if ($report['ledger']['agrees'])
                                <span class="erp-status erp-status-posted">agrees</span>
                            @else
                                ৳ {{ number_format($report['ledger']['difference'], 2) }}
                            @endif
                        </th>
                    </tr>
                </tfoot>
            </table>
        </div>
        @if ($report['waiting']['rows'] > 0 || $report['waiting']['reversed'] > 0)
            <div class="p-3 pt-0">
                <p class="erp-filter-note mb-0">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    Not in these totals: <strong>{{ $report['waiting']['rows'] }}</strong> expense(s) worth ৳ {{ number_format($report['waiting']['amount'], 2) }} still
                    waiting for a signature, and ৳ {{ number_format($report['waiting']['reversed'], 2) }} that was reversed — a reversal has its own entry in the ledger, so
                    subtracting it here would count it twice.
                </p>
            </div>
        @endif
    </section>

    <x-ui.related-pages />
@endsection
