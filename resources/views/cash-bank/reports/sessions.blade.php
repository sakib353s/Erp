@extends('layouts.app')

@section('page_title', 'Till variances')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Cash Reports · Session variance"
        title="What the tills counted"
        subtitle="The one report in this module that does not read the ledger, and it says so: a session's expected cash is the till's own arithmetic — opening float, plus cash sales, plus cash in, minus cash out — and the counted figure is what a person said was in the tin. The difference between them is what a manager chases."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash.reports.book', request()->query()) }}">
                <i class="bi bi-journal-text" aria-hidden="true"></i> Cash book
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.cash-counts') }}">
                <i class="bi bi-clipboard-check" aria-hidden="true"></i> Cash counts
            </a>
            <a class="btn btn-primary" href="{{ route('cash.reports.sessions', request()->query() + ['format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> Export CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Sessions" :value="$result['totals']['sessions']" icon="bi-display"
                  :hint="$result['totals']['open'].' still open · '.$result['totals']['closed'].' closed and counted'" />
        <x-ui.kpi label="Cash sales through the tills"
                  value="৳ {{ number_format((float) $result['totals']['cash_sales'], 2) }}"
                  icon="bi-cash-stack"
                  :hint="'Other methods: ৳ '.number_format((float) $result['totals']['non_cash_sales'], 2)" />
        <x-ui.kpi label="Counted short" value="৳ {{ number_format(abs((float) $result['totals']['counted_short']), 2) }}"
                  icon="bi-arrow-down-circle"
                  :hint="$result['totals']['with_variance'].' session(s) did not agree with the till'" />
        <x-ui.kpi label="Net difference" value="৳ {{ number_format((float) $result['totals']['net_variance'], 2) }}"
                  icon="{{ (float) $result['totals']['net_variance'] >= 0 ? 'bi-check2-circle' : 'bi-exclamation-triangle' }}"
                  :hint="$result['totals']['worst']
                      ? 'Worst single session: '.$result['totals']['worst']['session_no'].' at ৳ '.number_format((float) $result['totals']['worst']['variance'], 2)
                      : 'Every counted session agreed'" />
    </div>

    @if ($result['totals']['with_variance'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $result['totals']['with_variance'] }} session(s) did not add up</strong>
                Short by ৳ {{ number_format(abs((float) $result['totals']['counted_short']), 2) }}, over by
                ৳ {{ number_format((float) $result['totals']['counted_over'], 2) }}. A till variance is not a posting — the count desk is where a difference
                against the books is answered; this page is where it is noticed.
            </div>
        </div>
    @endif

    @include('cash-bank.reports.partials.filters', [
        'action' => route('cash.reports.sessions'),
        'branches' => $result['branches'],
        'extra' => view('cash-bank.reports.partials.session-extra', ['filters' => $filters])->render(),
    ])

    <x-ui.table-shell title="Session by session" :count="$result['rows']->count().' session(s)'">
        <thead>
            <tr>
                <th>Session</th>
                <th>Branch</th>
                <th>Opened</th>
                <th class="erp-th-num">Float</th>
                <th class="erp-th-num">Cash sales</th>
                <th class="erp-th-num">Cash in</th>
                <th class="erp-th-num">Cash out</th>
                <th class="erp-th-num">Expected</th>
                <th class="erp-th-num">Counted</th>
                <th class="erp-th-num">Difference</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($result['rows'] as $row)
                <tr>
                    <td>
                        <span class="erp-cell-strong font-monospace">{{ $row['session_no'] }}</span>
                        <div class="erp-td-muted">{{ $row['opened_by'] }}</div>
                    </td>
                    <td class="erp-td-muted">{{ $row['branch'] ?? '—' }}</td>
                    <td>
                        {{ $row['opened_at']?->format('d M H:i') }}
                        @if ($row['status'] === 'open')
                            <span class="erp-status erp-status-active ms-1">open</span>
                        @else
                            <div class="erp-td-muted">closed {{ $row['closed_at']?->format('d M H:i') }}</div>
                        @endif
                    </td>
                    <td class="erp-td-num">{{ number_format((float) $row['float'], 2) }}</td>
                    <td class="erp-td-num">{{ number_format((float) $row['cash_sales'], 2) }}</td>
                    <td class="erp-td-num">{{ number_format((float) $row['cash_in'], 2) }}</td>
                    <td class="erp-td-num">{{ number_format((float) $row['cash_out'], 2) }}</td>
                    <td class="erp-td-num">{{ number_format((float) $row['expected'], 2) }}</td>
                    <td class="erp-td-num">{{ $row['counted'] !== null ? number_format((float) $row['counted'], 2) : '—' }}</td>
                    <td class="erp-td-num">
                        @if ($row['variance'] === null)
                            <span class="erp-td-muted">not counted yet</span>
                        @elseif (! $row['has_variance'])
                            <span class="erp-status erp-status-posted">agreed</span>
                        @elseif ($row['short'])
                            <span class="erp-money-out">-{{ number_format(abs((float) $row['variance']), 2) }}</span>
                        @else
                            <span class="erp-money-in">+{{ number_format((float) $row['variance'], 2) }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10">
                        <x-ui.empty icon="bi-display" title="No till was opened in this window"
                                    text="Sessions appear here once a counter is opened and closed. Widen the dates to look further back." />
                    </td>
                </tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Added up</th>
                <th class="erp-th-num"></th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['cash_sales'], 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['cash_in'], 2) }}</th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['cash_out'], 2) }}</th>
                <th class="erp-th-num" colspan="2"></th>
                <th class="erp-th-num">{{ number_format((float) $result['totals']['net_variance'], 2) }}</th>
            </tr>
        </tfoot>
    </x-ui.table-shell>

    <div class="erp-split mt-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Where these figures come from</h2>
                    <p class="erp-card-sub">Worth reading once, because it is the one page in the module that is not the ledger's.</p>
                </div>
            </header>
            <dl class="erp-dl erp-dl-striped">
                <dt>Expected cash</dt>
                <dd>Opening float + cash sales + cash in − cash out, computed when the session is closed and frozen on the row.</dd>
                <dt>Counted</dt>
                <dd>What the person behind the till said was in the tin. A session still open has no counted figure.</dd>
                <dt>Session variance</dt>
                <dd>Counted − expected. This is the till's own arithmetic, not a posting, so it does not appear in the ledger.</dd>
                <dt>Cash counts</dt>
                <dd>A drawer counted against the <em>books</em> is a different document with its own screen — and there the difference does post.</dd>
            </dl>
        </section>
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">What to do about a short till</h2>
                    <p class="erp-card-sub">The order the desk expects.</p>
                </div>
            </header>
            <div class="p-3">
                <ol class="erp-stepper">
                    <li class="is-done"><span class="erp-stepper-mark">1</span>
                        <strong>Count it again</strong> — with the supervisor, before anything is written down. Most variances are arithmetic.</li>
                    <li class="is-current"><span class="erp-stepper-mark">2</span>
                        <strong>Count the drawer against the books</strong> on the count desk: that document freezes the ledger's figure for the day and posts only the difference.</li>
                    <li><span class="erp-stepper-mark">3</span>
                        <strong>Above the company's tolerance it waits</strong> for somebody other than the counter — money that is missing must not be written off by the person holding it.</li>
                </ol>
                <a class="btn btn-outline-secondary mt-2" href="{{ route('cash-bank.cash-counts') }}">
                    <i class="bi bi-clipboard-check" aria-hidden="true"></i> Go to the count desk
                </a>
            </div>
        </section>
    </div>

    <x-ui.related-pages />
@endsection
