@extends('layouts.app')

@section('page_title', 'Reconcile '.$account->name)

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · {{ $instrument === 'wallet' ? 'Mobile banking' : 'Bank accounts' }}"
        title="{{ $account->name }}"
        subtitle="A reconciliation is an argument, not a formality: it lines the bank's statement up against the posted journal lines on this account and names what is left over on each side. When the leftovers account for the whole difference between the bank's closing balance and the books, the two agree — and until then, the difference is the number to chase."
        :pin="true">
        <x-slot:actions>
            @if ($perm('bank.view'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.book', ['account' => $account->id]) }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> The book
                </a>
            @endif
            @if ($perm('bank.accounts'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                    <i class="bi bi-bank" aria-hidden="true"></i> Money accounts
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('statement')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @error('reconciliation')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if (! empty($rejected))
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-list-columns-reverse" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Rows that stopped the file</strong>
                <ul class="mb-0 ps-3">
                    @foreach ($rejected as $reason)
                        <li>{{ $reason }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if (! empty($preview))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-eye" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">Read first — nothing is stored yet</strong>
                <code>{{ $preview['file'] }}</code> holds {{ number_format((int) $preview['rows']) }} movement(s) the desk can read.
                @if (! empty($preview['skipped']))
                    It also states
                    @foreach ($preview['skipped'] as $aside)
                        {{ $aside['kind'] === 'opening' ? 'an opening balance' : 'a period total' }}
                        @if ($aside['balance'] !== null)
                            of {{ number_format((float) $aside['balance'], 2) }}
                        @endif
                        on line {{ $aside['line'] }}@if (! $loop->last), and @endif
                    @endforeach
                    — read and set aside, because a brought-forward figure is not money moving.
                @endif
                @if (! empty($preview['ignored']))
                    <span class="d-block mt-1">Columns this desk does not read, kept out of the way: {{ implode(', ', $preview['ignored']) }}.</span>
                @endif
                @if (! empty($preview['errors']))
                    <span class="d-block mt-1">{{ count($preview['errors']) }} row(s) could not be read — importing this file would store none of it.</span>
                @endif
                Import it to land the lines, then reconcile the period below.
            </div>
        </div>
    @endif

    @if ($instrument === 'wallet' && ! $walletApiConnected)
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-plug" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">No provider API is connected — and this screen will not pretend one is</strong>
                {{ $label }} has no adapter to bKash or Nagad in this application. Reconcile the way the provider expects: export the statement from the provider app or portal as CSV, import it here, and match it against the books. The wallet's own balance is still the ledger's, and this desk is what proves it.
            </div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Book balance, today" :value="number_format((float) $position['balance'], 2)"
                  icon="bi-journal-text" hint="Posted lines on {{ $account->code }}, never a stored figure" />
        <x-ui.kpi label="Statement lines loaded" :value="number_format($window['lines'])" icon="bi-file-earmark-spreadsheet"
                  hint="{{ $window['from'] ? 'From '.$window['from'].' to '.($window['to'] ?? '—') : 'No statement imported yet' }}" />
        <x-ui.kpi label="With no counterpart" :value="number_format($unmatched->count())" icon="bi-question-circle"
                  hint="Money the bank moved that these books have not been told about" />
        <x-ui.kpi label="Reconciliations made" :value="number_format($history->count())" icon="bi-shield-check"
                  hint="Newest first, signed-off periods included" />
    </div>

    <div class="erp-split">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Import the statement</h2>
                <div class="erp-card-actions">
                    @if ($instrument === 'bank')
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.statement.template', ['account' => $account->id]) }}">
                            <i class="bi bi-download" aria-hidden="true"></i> Template
                        </a>
                    @endif
                </div>
            </header>

            <form class="p-3" method="POST" action="{{ route($routePrefix.'.statement.import', ['account' => $account->id]) }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-3">
                    <label class="form-label" for="statement-file">Statement file (CSV)</label>
                    <input class="form-control" type="file" id="statement-file" name="file" accept=".csv,text/csv,text/plain" required>
                </div>

                <div class="erp-filterbar-actions">
                    <button class="btn btn-outline-secondary" type="submit" name="preview" value="1">
                        <i class="bi bi-eye" aria-hidden="true"></i> Read it first
                    </button>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Import the statement
                    </button>
                </div>

                <div class="erp-filter-note mt-3">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>
                        Columns read: date, description, reference, debit, credit, balance. Dates are read day-first (03/04/2026 is 3 April) unless the file uses YYYY-MM-DD.
                        A statement is one document, so it imports whole or not at all: one unreadable row refuses the file and names the row, because half a statement reconciles to a difference nobody caused.
                    </span>
                </div>
            </form>
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Statement runs</h2>
                </header>
                @if ($runs->isEmpty())
                    <x-ui.empty title="Nothing imported yet" icon="bi-file-earmark-spreadsheet"
                                text="A bank book can be read without a statement, but it cannot be proved without one." />
                @else
                    <div class="erp-table-scroll">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr><th>File</th><th class="erp-th-num">Rows</th><th>Result</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($runs as $run)
                                    <tr>
                                        <td>
                                            <span class="erp-cell-strong">{{ $run->file_name }}</span>
                                            <span class="d-block erp-td-muted">
                                                {{ $run->created_at?->format('d M Y H:i') }}
                                                @if ($run->importedBy) · {{ $run->importedBy->name }} @endif
                                            </span>
                                        </td>
                                        <td class="erp-td-num">{{ number_format($run->imported_count ?: $run->row_count) }}</td>
                                        <td>
                                            <x-ui.status :value="$run->status" />
                                            @if (! empty($run->errors))
                                                <span class="d-block erp-td-muted mt-1">{{ count($run->errors) }} row(s) refused</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </aside>
    </div>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">
                Open a reconciliation
                <span class="erp-chip erp-chip-outline">both sides are frozen when it opens</span>
            </h2>
        </header>

        <form class="p-3" method="POST" action="{{ route($routePrefix.'.reconcile.open', ['account' => $account->id]) }}">
            @csrf
            <div class="erp-filterbar">
                <div class="erp-filter">
                    <label class="form-label" for="period_start">Period start</label>
                    <input class="form-control" type="date" id="period_start" name="period_start" value="{{ old('period_start', $defaults['period_start']) }}" required>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="period_end">Period end</label>
                    <input class="form-control" type="date" id="period_end" name="period_end" value="{{ old('period_end', $defaults['period_end']) }}" required>
                </div>
                <div class="erp-filter">
                    <label class="form-label" for="statement_closing">Statement closing balance</label>
                    <input class="form-control" type="number" step="0.01" id="statement_closing" name="statement_closing"
                           value="{{ old('statement_closing', $defaults['statement_closing'] !== null ? number_format((float) $defaults['statement_closing'], 2, '.', '') : '') }}" required>
                </div>
                <div class="erp-filter-note">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    The closing figure comes off the statement itself — the file's own running balance fills it in when the file carries one.
                    Everything else is derived: the books' balance on the period end, what is left unmatched on each side, and what nothing explains.
                </div>
            </div>

            <div class="mt-3">
                <label class="form-label" for="notes">What you already know about the leftovers</label>
                <input class="form-control" type="text" id="notes" name="notes" maxlength="1000" value="{{ old('notes') }}"
                       placeholder="e.g. two cheques presented after month-end; the 250 charge is the bank's, not ours yet">
            </div>

            <div class="erp-filterbar-actions mt-3">
                <button class="btn btn-primary" type="submit">
                    <i class="bi bi-shield-check" aria-hidden="true"></i> Open the reconciliation
                </button>
            </div>
        </form>
    </section>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">
                Reconciliations
                <span class="erp-chip erp-chip-outline">{{ $history->count() }} on this account</span>
            </h2>
        </header>

        @if ($history->isEmpty())
            <x-ui.empty title="This account has never been reconciled"
                        icon="bi-shield-check"
                        text="Import the statement for a period, then open a reconciliation: the desk will match what it can and name what it cannot." />
        @else
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th class="erp-th-num">Statement closing</th>
                            <th class="erp-th-num">Book balance</th>
                            <th class="erp-th-num">Unexplained</th>
                            <th>Matched</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($history as $reconciliation)
                            <tr>
                                <td>
                                    <span class="erp-cell-strong">{{ $reconciliation->label() }}</span>
                                    <span class="d-block erp-td-muted">
                                        Prepared by {{ $reconciliation->preparedBy?->name ?? '—' }}
                                        @if ($reconciliation->signed_off_at) · signed by {{ $reconciliation->signedOffBy?->name }} @endif
                                    </span>
                                </td>
                                <td class="erp-td-num">{{ number_format((float) $reconciliation->statement_closing, 2) }}</td>
                                <td class="erp-td-num">{{ number_format((float) $reconciliation->book_balance, 2) }}</td>
                                <td class="erp-td-num">
                                    <span class="{{ $reconciliation->isProved() ? 'erp-money-flat' : 'erp-money-out' }}">
                                        {{ number_format((float) $reconciliation->difference, 2) }}
                                    </span>
                                </td>
                                <td class="erp-td-muted">
                                    {{ number_format($reconciliation->matched_count) }} pair(s)
                                    <span class="d-block">{{ $reconciliation->unmatched_statement_count }} statement · {{ $reconciliation->unmatched_book_count }} book left</span>
                                </td>
                                <td><x-ui.status :value="$reconciliation->status" /></td>
                                <td class="erp-td-actions">
                                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.reconciliations.show', ['reconciliation' => $reconciliation->id]) }}">
                                        <i class="bi bi-arrow-up-right" aria-hidden="true"></i> Open
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">
                {{ $statementLines->count() < $statementLineTotal ? 'The latest statement lines on this account' : 'Every statement line on this account' }}
                <span class="erp-chip erp-chip-outline">{{ number_format($statementLineTotal) }} line(s)</span>
            </h2>
        </header>

        @if ($statementLines->isEmpty())
            <x-ui.empty title="No statement lines yet" icon="bi-file-earmark-spreadsheet"
                        text="Import the bank's CSV and every line it carries will be listed here — matched or not." />
        @else
            @if ($statementLines->count() < $statementLineTotal)
                <div class="erp-filter-note px-3 pt-3">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>
                        Showing the latest {{ number_format($statementLines->count()) }} of
                        {{ number_format($statementLineTotal) }} line(s) imported for this account. Every line is used when a
                        period is reconciled — this list is only what is drawn on the page.
                    </span>
                </div>
            @endif

            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr>
                            <th>Value date</th>
                            <th>What the bank says it was</th>
                            <th>Reference</th>
                            <th class="erp-th-num">Money in</th>
                            <th class="erp-th-num">Money out</th>
                            <th class="erp-th-num">Balance after</th>
                            <th>State</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($statementLines as $line)
                            <tr>
                                <td class="erp-td-muted">{{ $line->value_date?->toDateString() }}</td>
                                <td>{{ $line->description ?? '—' }}</td>
                                <td class="erp-td-muted">{{ $line->reference ?? '—' }}</td>
                                <td class="erp-td-num">
                                    @if (bccomp((string) $line->credit, '0.0000', 4) > 0)
                                        <span class="erp-money-in">{{ number_format((float) $line->credit, 2) }}</span>
                                    @else
                                        <span class="erp-td-muted">—</span>
                                    @endif
                                </td>
                                <td class="erp-td-num">
                                    @if (bccomp((string) $line->debit, '0.0000', 4) > 0)
                                        <span class="erp-money-out">{{ number_format((float) $line->debit, 2) }}</span>
                                    @else
                                        <span class="erp-td-muted">—</span>
                                    @endif
                                </td>
                                <td class="erp-td-num">{{ $line->balance_after !== null ? number_format((float) $line->balance_after, 2) : '—' }}</td>
                                <td>
                                    @if ($line->isMatched())
                                        <x-ui.status value="matched" label="matched" />
                                    @else
                                        <x-ui.status value="unmatched" label="no counterpart" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection
