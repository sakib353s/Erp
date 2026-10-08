@extends('layouts.app')

@section('page_title', 'Reconciliation '.$reconciliation->label())

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · {{ $instrument === 'wallet' ? 'Mobile banking' : 'Bank accounts' }} · {{ $account->code }}"
        title="{{ $reconciliation->label() }}"
        subtitle="This reconciliation was made on the figures frozen below, so it reads the same next year as the day it was made. Matching by hand restates the proof; it never rewrites what was compared."
        :pin="true">
        <x-slot:actions>
            @if ($perm('bank.view'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.book', ['account' => $account->id]) }}">
                    <i class="bi bi-journal-text" aria-hidden="true"></i> {{ $account->name }}'s book
                </a>
            @endif
            @php($statementDesk = $instrument === 'wallet' ? 'cash-bank.wallets.statement' : 'cash-bank.statement')
            @if ($perm($instrument === 'wallet' ? 'wallets.accounts' : 'bank.view'))
                <a class="btn btn-outline-secondary" href="{{ route($statementDesk, ['account' => $account->id]) }}">
                    <i class="bi bi-arrow-left" aria-hidden="true"></i> Statement desk
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

    @error('reconciliation')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Book balance at period end" :value="number_format((float) $reconciliation->book_balance, 2)"
                  icon="bi-journal-text" hint="Posted lines up to {{ $reconciliation->period_end?->toDateString() }}" />
        <x-ui.kpi label="Statement closing" :value="number_format((float) $reconciliation->statement_closing, 2)"
                  icon="bi-bank" hint="The bank's own figure for {{ $reconciliation->period_end?->toDateString() }}" />
        <x-ui.kpi label="Expected closing" :value="number_format((float) $reconciliation->expectedClosing(), 2)"
                  icon="bi-calculator" hint="Books plus what the bank moved, less what the bank has not seen" />
        <x-ui.kpi label="Unexplained" :value="number_format((float) $reconciliation->difference, 2)"
                  icon="bi-question-circle" hint="Must be 0.00 before anybody may sign it off" />
    </div>

    <div class="erp-split">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">
                    The proof
                    <x-ui.status :value="$reconciliation->status" />
                </h2>
            </header>

            <div class="p-3">
                <div class="erp-dl erp-dl-tight">
                    <dt>Book balance at period end</dt>
                    <dd class="erp-money-flat">{{ number_format((float) $reconciliation->book_balance, 2) }}</dd>

                    <dt>Add: statement lines not in the books</dt>
                    <dd>
                        <span class="{{ bccomp((string) $reconciliation->unmatched_statement_total, '0.0000', 4) < 0 ? 'erp-money-out' : 'erp-money-in' }}">
                            {{ number_format((float) $reconciliation->unmatched_statement_total, 2) }}
                        </span>
                        <span class="d-block erp-td-muted">{{ $reconciliation->unmatched_statement_count }} line(s) the bank moved that these books have not been told about</span>
                    </dd>

                    <dt>Less: book lines the bank has not processed</dt>
                    <dd>
                        <span class="{{ bccomp((string) $reconciliation->unmatched_book_total, '0.0000', 4) < 0 ? 'erp-money-out' : 'erp-money-in' }}">
                            {{ number_format((float) $reconciliation->unmatched_book_total, 2) }}
                        </span>
                        <span class="d-block erp-td-muted">{{ $reconciliation->unmatched_book_count }} line(s) these books moved that the bank has not seen</span>
                    </dd>

                    <dt>Expected statement closing</dt>
                    <dd class="erp-cell-strong">{{ number_format((float) $reconciliation->expectedClosing(), 2) }}</dd>

                    <dt>Statement closing, as the bank states it</dt>
                    <dd class="erp-cell-strong">{{ number_format((float) $reconciliation->statement_closing, 2) }}</dd>

                    <dt>Unexplained</dt>
                    <dd>
                        <span class="{{ $reconciliation->isProved() ? 'erp-money-flat' : 'erp-money-out' }}">
                            {{ number_format((float) $reconciliation->difference, 2) }}
                        </span>
                        <span class="d-block erp-td-muted">
                            {{ $reconciliation->isProved()
                                ? 'Every paisa of the gap is accounted for by the leftover lines on both sides.'
                                : 'The leftover lines do not explain the whole gap. That figure is the difference between what the bank says this account opened with and what the books carried — so it moves when the earlier period is reconciled, or when the closing balance was read off the wrong row below. Naming leftovers here restates the lists; it cannot move this number.' }}
                        </span>
                    </dd>
                </div>

                <div class="erp-filter-note mt-3">
                    <i class="bi bi-info-circle" aria-hidden="true"></i>
                    <span>
                        The bank's opening figure for this period was {{ number_format((float) $reconciliation->statement_opening, 2) }}; the books carried
                        {{ number_format((float) $reconciliation->book_opening, 2) }} at the same moment.
                        When those two agree, the leftovers below are the whole story.
                    </span>
                </div>

                @if ($reconciliation->notes)
                    <div class="erp-note erp-note-info mt-3">
                        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                        <div>
                            <strong class="d-block mb-1">Note left when it was opened</strong>
                            {{ $reconciliation->notes }}
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Sign-off</h2>
                </header>
                <div class="p-3">
                    <div class="erp-dl erp-dl-tight">
                        <dt>Prepared by</dt>
                        <dd>{{ $reconciliation->preparedBy?->name ?? '—' }}<span class="d-block erp-td-muted">{{ $reconciliation->created_at?->format('d M Y H:i') }}</span></dd>
                        <dt>Signed off</dt>
                        <dd>
                            {{ $reconciliation->signedOffBy?->name ?? 'Not yet' }}
                            <span class="d-block erp-td-muted">{{ $reconciliation->signed_off_at?->format('d M Y H:i') ?? 'Somebody other than the preparer has to check it' }}</span>
                        </dd>
                    </div>

                    @if ($reconciliation->isSignedOff())
                        <div class="erp-note erp-note-ok mt-3">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <div>Signed off. The lines below are history — nothing here can be re-matched afterwards.</div>
                        </div>
                    @else
                        <form class="mt-3" method="POST" action="{{ route('cash-bank.reconciliations.restate', ['reconciliation' => $reconciliation->id]) }}">
                            @csrf
                            <label class="form-label" for="statement_closing">The bank's closing figure</label>
                            <div class="input-group">
                                <input class="form-control" id="statement_closing" name="statement_closing" type="number" step="0.01"
                                       value="{{ number_format((float) $reconciliation->statement_closing, 2, '.', '') }}" required>
                                <button class="btn btn-outline-secondary" type="submit">Correct it</button>
                            </div>
                            <p class="erp-td-muted mt-2 mb-0">Read it straight off the statement's closing row. Everything else on this reconciliation — the lines that were compared — stays exactly as it is.</p>
                        </form>

                        <hr class="my-3">

                        <form class="mt-3" method="POST" action="{{ route('cash-bank.reconciliations.sign-off', ['reconciliation' => $reconciliation->id]) }}">
                            @csrf
                            <button class="btn btn-primary w-100" type="submit" @disabled(! $reconciliation->isProved())>
                                <i class="bi bi-shield-check" aria-hidden="true"></i> Sign this reconciliation off
                            </button>
                        </form>
                        <p class="erp-td-muted mt-2 mb-0">
                            @if ($reconciliation->isProved())
                                The arithmetic adds up. The signature says a second person looked at it.
                            @else
                                Not available yet: {{ number_format(abs((float) $reconciliation->difference), 2) }} is still unexplained, and signing that off would turn a question into a fact.
                            @endif
                        </p>
                    @endif
                </div>
            </div>
        </aside>
    </div>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">
                In the books, not on the statement
                <span class="erp-chip erp-chip-outline">{{ $findings['unmatched_book']->count() }} line(s)</span>
            </h2>
        </header>

        @if ($findings['unmatched_book']->isEmpty())
            <x-ui.empty title="Nothing left over on the books' side" icon="bi-check2-circle"
                        text="Every posted line in this period found its counterpart on the statement." />
        @else
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr><th>Date</th><th>Reference</th><th>What it was</th><th class="erp-th-num">Amount</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($findings['unmatched_book'] as $line)
                            <tr>
                                <td class="erp-td-muted">{{ $line->movement_date?->toDateString() }}</td>
                                <td class="font-monospace">{{ $line->reference ?? '—' }}</td>
                                <td>{{ $line->description ?? '—' }}</td>
                                <td class="erp-td-num">
                                    <span class="{{ $line->direction() === 'out' ? 'erp-money-out' : 'erp-money-in' }}">
                                        {{ number_format((float) $line->amount, 2) }}
                                    </span>
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
                On the statement, not in the books
                <span class="erp-chip erp-chip-outline">{{ $findings['unmatched_statement']->count() }} line(s)</span>
            </h2>
        </header>

        @if ($findings['unmatched_statement']->isEmpty())
            <x-ui.empty title="Nothing left over on the bank's side" icon="bi-check2-circle"
                        text="Every statement line in this period was traced to a posted line." />
        @else
            <div class="erp-table-scroll">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th>Value date</th>
                            <th>What the bank says it was</th>
                            <th>Reference</th>
                            <th class="erp-th-num">Amount</th>
                            <th>Match by hand</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($findings['unmatched_statement'] as $line)
                            <tr>
                                <td class="erp-td-muted">{{ $line->movement_date?->toDateString() }}</td>
                                <td>{{ $line->description ?? '—' }}</td>
                                <td class="font-monospace">{{ $line->reference ?? '—' }}</td>
                                <td class="erp-td-num">
                                    <span class="{{ $line->direction() === 'out' ? 'erp-money-out' : 'erp-money-in' }}">
                                        {{ number_format((float) $line->amount, 2) }}
                                    </span>
                                </td>
                                <td>
                                    @if ($reconciliation->isSignedOff())
                                        <span class="erp-td-muted">Signed off — history</span>
                                    @elseif ($findings['unmatched_book']->isEmpty())
                                        <span class="erp-td-muted">Nothing left in the books to pair with this line</span>
                                    @else
                                        <form class="d-flex gap-2" method="POST" action="{{ route('cash-bank.reconciliations.match', ['reconciliation' => $reconciliation->id]) }}">
                                            @csrf
                                            <input type="hidden" name="statement_line_id" value="{{ $line->id }}">
                                            <label class="visually-hidden" for="book_line_{{ $line->id }}">Book line</label>
                                            <select class="form-select form-select-sm" id="book_line_{{ $line->id }}" name="book_line_id" required>
                                                @foreach ($findings['unmatched_book'] as $book)
                                                    <option value="{{ $book->id }}">
                                                        {{ $book->movement_date?->toDateString() }} · {{ $book->reference ?? 'no reference' }} · {{ number_format((float) $book->amount, 2) }}
                                                    </option>
                                                @endforeach
                                            </select>
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">Match</button>
                                        </form>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="erp-filter-note p-3">
                <i class="bi bi-info-circle" aria-hidden="true"></i>
                <span>Only lines of the same amount can be paired, and a pair made by the rule cannot be un-picked one line at a time — re-open the period with a wider date range instead.</span>
            </div>
        @endif
    </section>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <h2 class="erp-card-title">
                Matched pairs
                <span class="erp-chip erp-chip-outline">{{ $matched->count() }}</span>
            </h2>
        </header>

        @if ($matched->isEmpty())
            <x-ui.empty title="Nothing matched in this period" icon="bi-shuffle"
                        text="A period where nothing matches is not a failure — it means every movement of the money happened elsewhere." />
        @else
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr><th>Statement date</th><th>Reference</th><th>What the bank says it was</th><th class="erp-th-num">Amount</th><th>Matched how</th><th></th></tr>
                    </thead>
                    <tbody>
                        @foreach ($matched as $line)
                            <tr>
                                <td class="erp-td-muted">{{ $line->movement_date?->toDateString() }}</td>
                                <td class="font-monospace">{{ $line->reference ?? '—' }}</td>
                                <td>{{ $line->description ?? '—' }}</td>
                                <td class="erp-td-num">
                                    <span class="{{ $line->direction() === 'out' ? 'erp-money-out' : 'erp-money-in' }}">{{ number_format((float) $line->amount, 2) }}</span>
                                </td>
                                <td>
                                    <span class="erp-chip erp-chip-outline">{{ $line->match_type === 'manual' ? 'by hand' : 'same amount, close in date' }}</span>
                                </td>
                                <td class="erp-td-actions">
                                    @if (! $reconciliation->isSignedOff() && $line->match_type === 'manual')
                                        <form method="POST" action="{{ route('cash-bank.reconciliations.unmatch', ['reconciliation' => $reconciliation->id]) }}">
                                            @csrf
                                            <input type="hidden" name="line_id" value="{{ $line->id }}">
                                            <button class="btn btn-sm btn-link" type="submit">Undo</button>
                                        </form>
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
