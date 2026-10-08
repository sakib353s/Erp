@extends('layouts.app')

@section('page_title', 'Bank charges')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Bank accounts · Bank charge auto-posting"
        title="What the bank takes without asking"
        subtitle="Account maintenance, SMS alerts, commission on withdrawals: a current account quietly loses money every quarter and none of it arrives as a bill. A rule written here says what the bank takes and when, and from then on the charge posts by itself — Dr the charge, Cr the account it came out of, two lines, no party, because nobody was paid."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts') }}">
                <i class="bi bi-bank" aria-hidden="true"></i> The accounts
            </a>
            @if ($due > 0)
                <form method="POST" action="{{ route('cash-bank.bank-charges.run') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-lightning-charge" aria-hidden="true"></i> Run what is due
                        <span class="erp-chip erp-chip-warn ms-1">{{ $due }}</span>
                    </button>
                </form>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('bank_charge')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Taken this month"
            value="৳ {{ $summary['month'] }}"
            icon="bi-cash-coin"
            :hint="$summary['month_count'].' charge(s) posted since the first of the month'" />
        <x-ui.kpi
            label="Taken this year"
            value="৳ {{ $summary['year'] }}"
            icon="bi-calendar-range"
            :hint="$summary['count_year'].' charge(s) — what the banks have taken so far'" />
        <x-ui.kpi
            label="Average charge"
            value="৳ {{ $summary['average'] }}"
            icon="bi-graph-up"
            hint="This year's charges divided by how many there were" />
        <x-ui.kpi
            label="Rules in place"
            value="{{ $summary['active_rules'] }} / {{ $summary['rules'] }}"
            icon="bi-sliders"
            :hint="$summary['due'] > 0
                ? $summary['due'].' have come due — ৳ '.$summary['due_value'].' to post'
                : 'Nothing has come due yet'" />
    </div>

    @if ($due > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $due }} rule(s) have come due</strong>
                ৳ {{ $summary['due_value'] }} in charges is waiting to be posted. The scheduled run does this every morning; the
                button above is for when the statement cannot wait until tomorrow.
            </div>
        </div>
    @endif

    @if ($rules->isEmpty())
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <div>
                No rule has been written yet, so nothing posts by itself. A charge that only ever appears on a statement can still
                be recorded below — the rule is what saves somebody from having to notice it again next quarter.
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.bank-charges') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="account">Account</label>
            <select class="form-select" id="account" name="account">
                <option value="">Every account</option>
                @foreach ($accounts as $account)
                    <option value="{{ $account->id }}" @selected($filters['account'] === $account->id)>
                        {{ $account->code }} — {{ $account->name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="status">State</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                @foreach (\App\Domain\CashBank\BankCharge::STATUSES as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="origin">Came from</label>
            <select class="form-select" id="origin" name="origin">
                <option value="">Rules and the statement</option>
                <option value="rule" @selected($filters['origin'] === 'rule')>A rule, posted by itself</option>
                <option value="manual" @selected($filters['origin'] === 'manual')>Recorded by hand</option>
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" id="from" name="from" type="date" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" id="to" name="to" type="date" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="{{ $filters['q'] }}" placeholder="Charge number, what it was for, the bank's reference">
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a class="btn btn-link" href="{{ route('cash-bank.bank-charges') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="What the banks have taken" :count="$charges->count().' charge(s) shown'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Charge</th>
                <th>Taken from</th>
                <th>Booked to</th>
                <th>Computed from</th>
                <th class="erp-th-num">Amount</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($charges as $charge)
                <tr>
                    <td>{{ $charge->charged_on->toDateString() }}</td>
                    <td>
                        <span class="erp-cell-strong">{{ $charge->charge_no }}</span>
                        <div class="erp-td-muted">{{ $charge->narration ?? '—' }}</div>
                    </td>
                    <td>
                        {{ $charge->account?->name }}
                        <div class="erp-td-muted">{{ $charge->account?->code }}</div>
                    </td>
                    <td>
                        {{ $charge->expenseAccount?->name }}
                        <div class="erp-td-muted">{{ $charge->expenseAccount?->code }}</div>
                    </td>
                    <td>
                        {{ $charge->basisLabel() }}
                        <div class="erp-td-muted">{{ $charge->originLabel() }}</div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $charge->amount, 2) }}</td>
                    <td>
                        <x-ui.status :value="$charge->status" :label="$charge->statusLabel()" />
                        @if ($charge->isReversed())
                            <div class="erp-td-muted">
                                answered by {{ $charge->reversalEntry?->entry_no }}
                                @if ($charge->reversal_reason)
                                    — {{ $charge->reversal_reason }}
                                @endif
                            </div>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        @if (! $charge->isReversed() && $charge->journalEntry)
                            <details>
                                <summary class="erp-td-muted">Reverse</summary>
                                <form class="mt-2" method="POST" action="{{ route('cash-bank.bank-charges.reverse', $charge) }}"
                                      data-confirm="Reverse {{ $charge->charge_no }}? The original entry stays exactly where it is and its answer is filed next to it.">
                                    @csrf
                                    <input class="form-control form-control-sm mb-2" name="reason" maxlength="300" required
                                           placeholder="Keyed twice / the bank reversed it">
                                    <button class="btn btn-sm btn-outline-secondary" type="submit">Reverse it</button>
                                </form>
                            </details>
                        @elseif ($charge->isReversed())
                            <span class="erp-td-muted">history, not a button</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty
                            title="No bank charge has been recorded yet"
                            icon="bi-cash-coin"
                            text="Write a rule below and the desk will post the bank's charges on their own day — or record one by hand from the line on a statement." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <section class="erp-card mt-3">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">The rules — what the bank is told to take</h2>
                <p class="erp-card-sub">
                    A rule is a standing statement about a tariff, not a posting: writing one charges nothing. On its day the
                    charge posts by itself, and a rule and a hand-recorded charge can never duplicate each other because the same
                    rule cannot post twice for the same date.
                </p>
            </div>
        </header>
        <div>
            <div class="erp-table-scroll">
                <table class="table erp-table erp-table-stack">
                    <thead>
                        <tr>
                            <th>Rule</th>
                            <th>Account</th>
                            <th>Booked to</th>
                            <th>Terms</th>
                            <th>Rhythm</th>
                            <th>Next charge</th>
                            <th class="erp-th-num">Charged so far</th>
                            <th>State</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($rules as $rule)
                            <tr>
                                <td>
                                    <span class="erp-cell-strong">{{ $rule->name }}</span>
                                    @if ($rule->narration)
                                        <div class="erp-td-muted">{{ $rule->narration }}</div>
                                    @endif
                                </td>
                                <td>
                                    {{ $rule->account?->name }}
                                    <div class="erp-td-muted">{{ $rule->account?->code }}</div>
                                </td>
                                <td>
                                    {{ $rule->expenseAccount?->name }}
                                    <div class="erp-td-muted">{{ $rule->expenseAccount?->code }}</div>
                                </td>
                                <td>
                                    {{ $rule->termsLabel() }}
                                    @isset($quotes[$rule->id])
                                        <div class="erp-td-muted">
                                            would charge ৳ {{ $quotes[$rule->id]['amount'] }} today
                                            @if ($rule->basis === \App\Domain\CashBank\BankChargeRule::BASIS_PERCENT)
                                                on {{ $quotes[$rule->id]['turnover'] }} of withdrawals
                                                @if ($quotes[$rule->id]['from'])
                                                    since {{ $quotes[$rule->id]['from'] }}
                                                @endif
                                            @endif
                                        </div>
                                    @endisset
                                </td>
                                <td>{{ $rule->rhythm() }}</td>
                                <td>
                                    {{ $rule->next_due_on?->toDateString() ?? '—' }}
                                    @if ($rule->isDue())
                                        <div class="erp-td-muted">due {{ $rule->daysLate() }} day(s) ago</div>
                                    @elseif ($rule->last_charged_on)
                                        <div class="erp-td-muted">last charged {{ $rule->last_charged_on->toDateString() }}</div>
                                    @endif
                                </td>
                                <td class="erp-td-num">
                                    {{ $rule->charged_count }}
                                    <div class="erp-td-muted">{{ $rule->charges_count }} in the register</div>
                                </td>
                                <td>
                                    <x-ui.status :value="$rule->isActive() ? 'active' : 'paused'"
                                                 :label="$rule->isActive() ? 'Running' : 'Paused'" />
                                    @if ($rule->ends_on)
                                        <div class="erp-td-muted">ends {{ $rule->ends_on->toDateString() }}</div>
                                    @endif
                                </td>
                                <td class="erp-td-actions">
                                    @if ($mayConfigure)
                                        <form method="POST" action="{{ route('cash-bank.bank-charge-rules.toggle', $rule) }}">
                                            @csrf
                                            <button class="btn btn-sm btn-outline-secondary" type="submit">
                                                @if ($rule->isActive())
                                                    <i class="bi bi-pause" aria-hidden="true"></i> Pause
                                                @else
                                                    <i class="bi bi-play" aria-hidden="true"></i> Resume
                                                @endif
                                            </button>
                                        </form>
                                        <details class="mt-2">
                                            <summary class="erp-td-muted">Rewrite</summary>
                                            <form class="mt-2" method="POST" action="{{ route('cash-bank.bank-charge-rules.update', $rule) }}">
                                                @csrf
                                                @method('PUT')
                                                <div class="mb-2">
                                                    <label class="form-label" for="name-{{ $rule->id }}">Name</label>
                                                    <input class="form-control form-control-sm" id="name-{{ $rule->id }}" name="name" value="{{ $rule->name }}" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="account-{{ $rule->id }}">Taken from</label>
                                                    <select class="form-select form-select-sm" id="account-{{ $rule->id }}" name="account_id" required>
                                                        @foreach ($accounts as $account)
                                                            <option value="{{ $account->id }}" @selected($rule->account_id === $account->id)>
                                                                {{ $account->code }} — {{ $account->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="expense-{{ $rule->id }}">Booked to</label>
                                                    <select class="form-select form-select-sm" id="expense-{{ $rule->id }}" name="expense_account_id" required>
                                                        @foreach ($expenseAccounts as $account)
                                                            <option value="{{ $account->id }}" @selected($rule->expense_account_id === $account->id)>
                                                                {{ $account->code }} — {{ $account->name }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="basis-{{ $rule->id }}">Terms</label>
                                                    <select class="form-select form-select-sm" id="basis-{{ $rule->id }}" name="basis" required>
                                                        @foreach (\App\Domain\CashBank\BankChargeRule::BASES as $key => $label)
                                                            <option value="{{ $key }}" @selected($rule->basis === $key)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="amount-{{ $rule->id }}">Fixed amount</label>
                                                    <input class="form-control form-control-sm" id="amount-{{ $rule->id }}" name="amount" value="{{ $rule->amount }}">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="rate-{{ $rule->id }}">Rate %</label>
                                                    <input class="form-control form-control-sm" id="rate-{{ $rule->id }}" name="rate_percent" value="{{ $rule->rate_percent }}">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="min-{{ $rule->id }}">Minimum</label>
                                                    <input class="form-control form-control-sm" id="min-{{ $rule->id }}" name="min_amount" value="{{ $rule->min_amount }}">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="frequency-{{ $rule->id }}">How often</label>
                                                    <select class="form-select form-select-sm" id="frequency-{{ $rule->id }}" name="frequency" required>
                                                        @foreach (\App\Domain\CashBank\BankChargeRule::FREQUENCIES as $key => $label)
                                                            <option value="{{ $key }}" @selected($rule->frequency === $key)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="day-{{ $rule->id }}">On which day</label>
                                                    <input class="form-control form-control-sm" id="day-{{ $rule->id }}" name="day_of_month" value="{{ $rule->day_of_month }}">
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="starts-{{ $rule->id }}">First date</label>
                                                    <input class="form-control form-control-sm" id="starts-{{ $rule->id }}" name="starts_on" type="date" value="{{ $rule->starts_on?->toDateString() }}" required>
                                                </div>
                                                <div class="mb-2">
                                                    <label class="form-label" for="ends-{{ $rule->id }}">Last date</label>
                                                    <input class="form-control form-control-sm" id="ends-{{ $rule->id }}" name="ends_on" type="date" value="{{ $rule->ends_on?->toDateString() }}">
                                                </div>
                                                <input type="hidden" name="is_active" value="0">
                                                <label class="form-check mb-2">
                                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($rule->isActive())>
                                                    <span class="form-check-label">Running</span>
                                                </label>
                                                <button class="btn btn-sm btn-primary" type="submit">Save the rule</button>
                                            </form>
                                        </details>
                                    @else
                                        <span class="erp-td-muted">Somebody who writes the tariff rules can pause this</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9">
                                    <x-ui.empty
                                        title="No rule has been written yet"
                                        icon="bi-sliders"
                                        text="Until there is one, every bank charge has to be noticed by a person reading a statement. A rule is how it stops depending on somebody remembering." />
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <div class="erp-split mt-3">
        @if ($accounts->isNotEmpty() && $expenseAccounts->isNotEmpty())
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Record a charge</h2>
                        <p class="erp-card-sub">
                            For a charge that has already happened: pick the rule and the desk computes the amount from its terms,
                            or leave the rule out and enter the figure the statement shows.
                        </p>
                    </div>
                </header>
                <div>
                    <form method="POST" action="{{ route('cash-bank.bank-charges.store') }}">
                        @csrf
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_id">Rule</label>
                                <select class="form-select" id="rule_id" name="rule_id">
                                    <option value="">No rule — I have the figure</option>
                                    @foreach ($rules as $rule)
                                        <option value="{{ $rule->id }}" @selected(old('rule_id') == $rule->id)>
                                            {{ $rule->name }} — {{ $rule->termsLabel() }}
                                        </option>
                                    @endforeach
                                </select>
                                <small class="form-text">Naming a rule takes the account, the expense account and the amount from it.</small>
                                @error('rule_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="charge_account">Taken from</label>
                                <select class="form-select" id="charge_account" name="account_id">
                                    <option value="">Use the rule's account</option>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>
                                            {{ $account->code }} — {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="charge_expense">Booked to</label>
                                <select class="form-select" id="charge_expense" name="expense_account_id">
                                    <option value="">
                                        {{ $defaultExpenseAccount ? $defaultExpenseAccount->code.' — '.$defaultExpenseAccount->name : 'Choose the expense account' }}
                                    </option>
                                    @foreach ($expenseAccounts as $account)
                                        <option value="{{ $account->id }}" @selected(old('expense_account_id') == $account->id)>
                                            {{ $account->code }} — {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('expense_account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="charge_amount">Amount</label>
                                <input class="form-control" id="charge_amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="Leave empty to use the rule's terms">
                                @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="charged_on">Date the bank took it</label>
                                <input class="form-control" id="charged_on" name="charged_on" type="date" value="{{ old('charged_on', now()->toDateString()) }}" required>
                                @error('charged_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="reference">Bank's reference</label>
                                <input class="form-control" id="reference" name="reference" value="{{ old('reference') }}" maxlength="120" placeholder="From the statement, if there is one">
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="charge_narration">What it was for</label>
                                <input class="form-control" id="charge_narration" name="narration" value="{{ old('narration') }}" maxlength="300" placeholder="Quarterly account maintenance">
                            </div>
                        </div>
                        <button class="btn btn-primary mt-2" type="submit">
                            <i class="bi bi-cash-coin" aria-hidden="true"></i> Record and post the charge
                        </button>
                    </form>
                </div>
            </section>
        @endif

        @if ($mayConfigure && $accounts->isNotEmpty() && $expenseAccounts->isNotEmpty())
            <section class="erp-card">
                <header class="erp-card-head">
                    <div>
                        <h2 class="erp-card-title">Write a rule</h2>
                        <p class="erp-card-sub">
                            Nothing is charged by writing one. A commission is computed on the money that left the account since
                            the last charge, so the figure can be checked against the bank's own arithmetic.
                        </p>
                    </div>
                </header>
                <div>
                    <form method="POST" action="{{ route('cash-bank.bank-charge-rules.store') }}">
                        @csrf
                        <div class="erp-form-grid">
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_name">Name</label>
                                <input class="form-control" id="rule_name" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Account maintenance" required>
                                @error('name')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_account">Taken from</label>
                                <select class="form-select" id="rule_account" name="account_id" required>
                                    @foreach ($accounts as $account)
                                        <option value="{{ $account->id }}" @selected(old('account_id') == $account->id)>
                                            {{ $account->code }} — {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_expense">Booked to</label>
                                <select class="form-select" id="rule_expense" name="expense_account_id" required>
                                    @foreach ($expenseAccounts as $account)
                                        <option value="{{ $account->id }}" @selected(old('expense_account_id', $defaultExpenseAccount?->id) == $account->id)>
                                            {{ $account->code }} — {{ $account->name }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('expense_account_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_basis">Terms</label>
                                <select class="form-select" id="rule_basis" name="basis" required>
                                    @foreach (\App\Domain\CashBank\BankChargeRule::BASES as $key => $label)
                                        <option value="{{ $key }}" @selected(old('basis', 'fixed') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('basis')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_amount">Fixed amount</label>
                                <input class="form-control" id="rule_amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" placeholder="500.00">
                                <small class="form-text">Used when the terms are a fixed amount.</small>
                                @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_rate">Rate %</label>
                                <input class="form-control" id="rule_rate" name="rate_percent" inputmode="decimal" value="{{ old('rate_percent') }}" placeholder="0.15">
                                <small class="form-text">Used when the terms are a percentage of what left the account.</small>
                                @error('rate_percent')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_min">Minimum</label>
                                <input class="form-control" id="rule_min" name="min_amount" inputmode="decimal" value="{{ old('min_amount') }}" placeholder="100.00">
                                <small class="form-text">A floor under a commission — “0.15%, minimum ৳100”. A quarter in which nothing left the account is charged nothing.</small>
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_frequency">How often</label>
                                <select class="form-select" id="rule_frequency" name="frequency" required>
                                    @foreach (\App\Domain\CashBank\BankChargeRule::FREQUENCIES as $key => $label)
                                        <option value="{{ $key }}" @selected(old('frequency', 'quarterly') === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                                @error('frequency')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_day">On which day</label>
                                <input class="form-control" id="rule_day" name="day_of_month" inputmode="numeric" value="{{ old('day_of_month', 5) }}" placeholder="5">
                                <small class="form-text">A short month takes its last day rather than spilling into the next one.</small>
                                @error('day_of_month')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_starts">First date</label>
                                <input class="form-control" id="rule_starts" name="starts_on" type="date" value="{{ old('starts_on', now()->toDateString()) }}" required>
                                @error('starts_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_ends">Last date</label>
                                <input class="form-control" id="rule_ends" name="ends_on" type="date" value="{{ old('ends_on') }}">
                                <small class="form-text">Optional. A rule whose last date has passed stops itself.</small>
                                @error('ends_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                            </div>
                            <div class="erp-form-field">
                                <label class="form-label" for="rule_branch">Branch</label>
                                <select class="form-select" id="rule_branch" name="branch_id">
                                    @foreach ($branches as $branch)
                                        <option value="{{ $branch->id }}" @selected(old('branch_id') == $branch->id)>
                                            {{ $branch->name }}{{ $branch->is_default ? ' (head office)' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="erp-form-field erp-form-field-wide">
                                <label class="form-label" for="rule_narration">What it is</label>
                                <input class="form-control" id="rule_narration" name="narration" value="{{ old('narration') }}" maxlength="300" placeholder="Quarterly maintenance charge on the current account">
                            </div>
                        </div>
                        <input type="hidden" name="is_active" value="0">
                        <label class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked(old('is_active', '1') == '1')>
                            <span class="form-check-label">Run it</span>
                        </label>
                        <button class="btn btn-primary mt-2" type="submit">
                            <i class="bi bi-sliders" aria-hidden="true"></i> Write the rule
                        </button>
                    </form>
                </div>
            </section>
        @elseif ($mayConfigure && $expenseAccounts->isEmpty())
            <div class="erp-note erp-note-warn">
                <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                <div>
                    There is no expense account to book a bank charge to, and a charge has to sit somewhere. The standard chart's
                    <strong>5280 — Bank Charges</strong> is the one this desk expects; an accountant can add it and come back.
                </div>
            </div>
        @endif
    </div>

    @if (! $mayConfigure)
        <div class="erp-filter-note mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>
                You can record what the bank has taken and reverse a charge that was wrong. Writing the rules that post charges
                by themselves is a separate permission — it is the act that can quietly move money every quarter for years.
            </span>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
