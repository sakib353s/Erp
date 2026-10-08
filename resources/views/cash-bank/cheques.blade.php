@extends('layouts.app')

@section('page_title', 'Cheque register')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; bank · Cheque management"
        title="The cheque register"
        subtitle="A cheque is a promise, and this is the book of promises: what is in the drawer, what is with a bank, what has been paid — and what came back. Nothing here reaches the ledger until a bank actually pays it, because money that has been promised is not money that has arrived."
        :pin="true">
        <x-slot:actions>
            @if ($perm('cash.view'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                    <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
                </a>
            @endif
            @if ($perm('bank.reconcile'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.reconciliations') }}">
                    <i class="bi bi-shield-check" aria-hidden="true"></i> Bank reconciliation
                </a>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('cheque')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($moneyAccounts->isEmpty())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>
            <div>
                No money account is declared, so there is no account a cheque could clear through.
                @if ($perm('bank.accounts'))
                    <a class="fw-semibold" href="{{ route('cash-bank.accounts') }}">Declare one first</a>.
                @endif
            </div>
        </div>
    @endif

    <div class="erp-kpi-grid">
        <x-ui.kpi label="In hand" :value="number_format((float) $summary['in_hand']['total'], 2)"
                  icon="bi-journal-bookmark"
                  :hint="$summary['in_hand']['count'].' customer cheque(s) in the drawer — the books have not heard about them yet'" />
        <x-ui.kpi label="With the bank" :value="number_format((float) $summary['deposited']['total'], 2)"
                  icon="bi-bank"
                  :hint="$summary['deposited']['count'].' deposited, none cleared yet'" />
        <x-ui.kpi label="We wrote, not presented" :value="number_format((float) $summary['issued_pending']['total'], 2)"
                  icon="bi-pencil-square"
                  :hint="$summary['issued_pending']['count'].' of our own cheques are still outstanding'" />
        <x-ui.kpi label="Post-dated" :value="number_format((float) $summary['post_dated']['total'], 2)"
                  icon="bi-calendar-event"
                  :hint="$summary['post_dated']['count'].' dated ahead — the desk refuses to bank them early'" />
        <x-ui.kpi label="Cleared this month" :value="number_format((float) $summary['cleared_this_month']['total'], 2)"
                  icon="bi-check2-circle"
                  :hint="$summary['cleared_this_month']['count'].' cheque(s) the bank actually paid'" />
        <x-ui.kpi label="Failed this month" :value="number_format((float) $summary['failed_this_month']['total'], 2)"
                  icon="bi-arrow-counterclockwise"
                  :hint="$summary['failed_this_month']['count'].' bounced or returned'" />
    </div>

    @if ($filterAccount !== null)
        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Still hanging over {{ $filterAccount->name }}</h2>
                    <p class="erp-card-sub">Promises written against this account that no bank has settled yet — the footnote a bank book needs before anybody signs it off.</p>
                </div>
                <div class="erp-card-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.book', ['account' => $filterAccount->id]) }}">
                        <i class="bi bi-journal-text" aria-hidden="true"></i> Its book
                    </a>
                </div>
            </header>

            @if ($outstandingOn->isEmpty())
                <div>
                    <p class="mb-0 erp-td-muted">
                        Nothing outstanding. Every cheque written against this account has been paid, returned or bounced —
                        which is what a clean book looks like.
                    </p>
                </div>
            @else
                <div>
                    <div class="erp-dl erp-dl-tight erp-dl-striped">
                        <dt>Outstanding cheques</dt>
                        <dd>{{ number_format($outstandingOn->count()) }}</dd>
                        <dt>Promised money</dt>
                        <dd class="erp-money-flat">{{ number_format((float) $outstandingOn->sum(fn ($cheque) => (float) $cheque->amount), 2) }}</dd>
                        <dt>Of that, post-dated</dt>
                        <dd class="erp-money-flat">{{ number_format((float) $outstandingOn->filter(fn ($cheque) => $cheque->isPostDated())->sum(fn ($cheque) => (float) $cheque->amount), 2) }}</dd>
                    </div>
                    <div class="erp-table-scroll mt-2">
                        <table class="erp-table erp-table-compact">
                            <thead>
                                <tr>
                                    <th>Cheque</th>
                                    <th>Party</th>
                                    <th class="erp-th-num">Amount</th>
                                    <th>State</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($outstandingOn as $outstanding)
                                    <tr data-erp-row-href="{{ route('cash-bank.cheques.show', ['cheque' => $outstanding->id]) }}">
                                        <td data-label="Cheque">
                                            <span class="erp-cell-strong font-monospace">{{ $outstanding->cheque_no }}</span>
                                            <span class="d-block erp-td-muted">{{ $outstanding->cheque_date?->format('d M Y') }}</span>
                                        </td>
                                        <td data-label="Party">{{ $outstanding->party_name }}</td>
                                        <td data-label="Amount" class="erp-td-num">
                                            <span class="{{ $outstanding->isReceived() ? 'erp-money-in' : 'erp-money-out' }}">
                                                {{ number_format((float) $outstanding->amount, 2) }}
                                            </span>
                                        </td>
                                        <td data-label="State">
                                            <x-ui.status :value="$outstanding->statusTone()" :label="$outstanding->statusLabel()" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.cheques') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="state">State</label>
            <select class="form-select" id="state" name="state">
                <option value="">Everything</option>
                @foreach ($states as $key => $label)
                    <option value="{{ $key }}" @selected($filters['state'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="direction">Direction</label>
            <select class="form-select" id="direction" name="direction">
                <option value="">Received and issued</option>
                @foreach ($directions as $key => $label)
                    <option value="{{ $key }}" @selected($filters['direction'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="account">Clears through</label>
            <select class="form-select" id="account" name="account">
                <option value="">Any money account</option>
                @foreach ($moneyAccounts as $account)
                    <option value="{{ $account->id }}" @selected($filters['account'] === $account->id)>
                        {{ $account->code }} — {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Find</label>
            <input class="form-control" type="search" id="q" name="q" value="{{ $filters['q'] }}"
                   placeholder="Cheque number, party or bank" data-erp-search>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a class="btn btn-link" href="{{ route('cash-bank.cheques') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="The register" :count="$cheques->count().' cheque(s) shown'">
        <thead>
            <tr>
                <th>Cheque</th>
                <th>Date written</th>
                <th>Party</th>
                <th>Clears through</th>
                <th>Settles</th>
                <th class="erp-th-num">Amount</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($cheques as $cheque)
                <tr data-erp-row-href="{{ route('cash-bank.cheques.show', ['cheque' => $cheque->id]) }}">
                    <td data-label="Cheque">
                        <span class="erp-cell-strong font-monospace">{{ $cheque->cheque_no }}</span>
                        <span class="d-block erp-td-muted">{{ $cheque->isReceived() ? 'Received from a customer' : 'Issued by us' }}</span>
                    </td>
                    <td data-label="Date written" class="erp-td-muted">
                        {{ $cheque->cheque_date?->format('d M Y') }}
                        @if ($cheque->isPostDated())
                            <span class="d-block"><span class="erp-chip erp-chip-outline">Post-dated</span></span>
                        @endif
                    </td>
                    <td data-label="Party">
                        {{ $cheque->party_name }}
                        <span class="d-block erp-td-muted">{{ $cheque->bank_name }}</span>
                    </td>
                    <td data-label="Clears through" class="erp-td-muted">{{ $cheque->account?->name ?? '—' }}</td>
                    <td data-label="Settles" class="erp-td-muted">{{ $cheque->counterAccount?->name ?? '—' }}</td>
                    <td data-label="Amount" class="erp-td-num">
                        <span class="{{ $cheque->isReceived() ? 'erp-money-in' : 'erp-money-out' }}">
                            {{ number_format((float) $cheque->amount, 2) }}
                        </span>
                    </td>
                    <td data-label="State">
                        <x-ui.status :value="$cheque->statusTone()" :label="$cheque->statusLabel()" />
                        @if ($cheque->isCleared() && $cheque->journalEntry)
                            <span class="d-block erp-td-muted font-monospace mt-1">{{ $cheque->journalEntry->entry_no }}</span>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.cheques.show', ['cheque' => $cheque->id]) }}">
                            <i class="bi bi-eye" aria-hidden="true"></i> Open
                        </a>
                        @if ($cheque->isIssued() && $perm('cheques.print'))
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.cheques.print', ['cheque' => $cheque->id]) }}" target="_blank" rel="noopener">
                                <i class="bi bi-printer" aria-hidden="true"></i> Print
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty icon="bi-journal-bookmark"
                                    title="No cheque matches this view"
                                    text="Write one below, or clear the filters. A cheque appears here the moment it is written, and stays until a bank pays it, returns it, or it bounces." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($perm('cheques.manage') && $moneyAccounts->isNotEmpty())
        <div class="erp-split mt-3">
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Write a cheque into the register</h2>
                        <p class="erp-card-sub">The slip in your hand is the source: its number, its date, the bank that printed it, and who it is between.</p>
                    </div>
                </header>
                <div>
                    <form method="POST" action="{{ route('cash-bank.cheques.store') }}">
                        @csrf

                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="direction">Which way does it run?</label>
                                <select class="form-select @error('direction') is-invalid @enderror" id="direction" name="direction" required>
                                    @foreach ($directions as $key => $label)
                                        <option value="{{ $key }}" @selected(old('direction', 'received') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('direction') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="cheque_no">Cheque number</label>
                                <input class="form-control font-monospace @error('cheque_no') is-invalid @enderror" type="text"
                                       id="cheque_no" name="cheque_no" value="{{ old('cheque_no') }}" maxlength="32"
                                       placeholder="004512" required>
                                <div class="form-text">The number the bank printed on it. Two live cheques on one account cannot share it.</div>
                                @error('cheque_no') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="cheque_date">Date written on it</label>
                                <input class="form-control @error('cheque_date') is-invalid @enderror" type="date"
                                       id="cheque_date" name="cheque_date" value="{{ old('cheque_date', now()->toDateString()) }}" required>
                                <div class="form-text">A future date makes it post-dated: the register accepts it, and then refuses to deposit, present or clear it early.</div>
                                @error('cheque_date') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="bank_name">Bank it is drawn on</label>
                                <input class="form-control @error('bank_name') is-invalid @enderror" type="text"
                                       id="bank_name" name="bank_name" value="{{ old('bank_name') }}" maxlength="80"
                                       placeholder="Islami Bank, Motijheel" required>
                                @error('bank_name') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="amount">Amount</label>
                                <div class="erp-input-group">
                                    <i class="bi bi-currency-exchange" aria-hidden="true"></i>
                                    <input class="form-control erp-num @error('amount') is-invalid @enderror" type="number"
                                           step="0.01" min="0.01" id="amount" name="amount" value="{{ old('amount') }}" required>
                                </div>
                                @error('amount') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="account_id">Clears through</label>
                                <select class="form-select @error('account_id') is-invalid @enderror" id="account_id" name="account_id" required>
                                    <option value="">— the cash, bank or wallet account —</option>
                                    @foreach ($moneyAccounts as $account)
                                        <option value="{{ $account->id }}" @selected((int) old('account_id') === $account->id)>
                                            {{ $account->code }} — {{ $account->name }}
                                            @if ($account->bank_name)· {{ $account->bank_name }}@endif
                                        </option>
                                    @endforeach
                                </select>
                                @error('account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="counter_account_id">What it settles</label>
                                <select class="form-select @error('counter_account_id') is-invalid @enderror" id="counter_account_id" name="counter_account_id" required>
                                    <option value="">— the account on the other side —</option>
                                    @foreach ($counterAccounts as $option)
                                        <option value="{{ $option['id'] }}" @selected((int) old('counter_account_id') === $option['id'])>
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>
                                <div class="form-text">
                                    A customer's receivable, a supplier's payable, the expense it pays — because this is the
                                    account the ledger will move when the bank finally pays it.
                                </div>
                                @error('counter_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="party_name">Party on the slip</label>
                                <input class="form-control @error('party_name') is-invalid @enderror" type="text"
                                       id="party_name" name="party_name" value="{{ old('party_name') }}" maxlength="160"
                                       placeholder="Who wrote it, or who it is made out to" required>
                                @error('party_name') <div class="text-danger small">{{ $message }}</div> @enderror
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="customer_id">Customer on the books (received)</label>
                                <select class="form-select" id="customer_id" name="customer_id">
                                    <option value="">— nobody on the books —</option>
                                    @foreach ($customers as $customer)
                                        <option value="{{ $customer->id }}" @selected((int) old('customer_id') === $customer->id)>
                                            {{ $customer->name }} ({{ $customer->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="supplier_id">Supplier on the books (issued)</label>
                                <select class="form-select" id="supplier_id" name="supplier_id">
                                    <option value="">— nobody on the books —</option>
                                    @foreach ($suppliers as $supplier)
                                        <option value="{{ $supplier->id }}" @selected((int) old('supplier_id') === $supplier->id)>
                                            {{ $supplier->name }} ({{ $supplier->code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="reference">Reference</label>
                                <input class="form-control font-monospace" type="text" id="reference" name="reference"
                                       value="{{ old('reference') }}" maxlength="64" placeholder="Invoice no., bill no…">
                            </div>

                            <div class="erp-form-field">
                                <label class="form-label" for="narration">What it was for</label>
                                <input class="form-control" type="text" id="narration" name="narration"
                                       value="{{ old('narration') }}" maxlength="500" placeholder="Shown on the entry and on the printed record">
                            </div>
                        </div>

                        <div class="d-flex flex-wrap gap-2 mt-3">
                            <button class="btn btn-primary" type="submit">
                                <i class="bi bi-journal-plus" aria-hidden="true"></i> Write it in
                            </button>
                        </div>
                    </form>
                </div>
            </section>

            <aside>
                <div class="erp-card">
                    <header class="erp-card-head">
                        <h2 class="erp-card-title">How this desk posts</h2>
                    </header>
                    <div>
                        <ul class="erp-list small mb-0">
                            <li>
                                <i class="bi bi-1-circle" aria-hidden="true"></i>
                                <span><strong>Nothing posts when it is written.</strong> A cheque in the register is a promise, and a ledger
                                carrying promises is a ledger nobody can reconcile.</span>
                            </li>
                            <li>
                                <i class="bi bi-2-circle" aria-hidden="true"></i>
                                <span><strong>Depositing is not paying.</strong> A handed-in cheque stays outstanding until the bank
                                clears it — the desk records the movement, not the money.</span>
                            </li>
                            <li>
                                <i class="bi bi-3-circle" aria-hidden="true"></i>
                                <span><strong>Clearing posts once.</strong> The money account against what the cheque settled, dated the day
                                the bank paid it.</span>
                            </li>
                            <li>
                                <i class="bi bi-4-circle" aria-hidden="true"></i>
                                <span><strong>Bouncing after clearing reverses it.</strong> The original entry stays where it is and a reversal
                                answers it, because the original really did happen.</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </aside>
        </div>
    @elseif (! $perm('cheques.manage'))
        <div class="erp-note erp-note-info mt-3">
            <i class="bi bi-lock" aria-hidden="true"></i>
            <div>
                You can read the register but not write in it — recording a cheque is the <code>cheques.manage</code> key.
                Saying a cheque cleared is a separate key again, because it moves the ledger.
            </div>
        </div>
    @endif

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
