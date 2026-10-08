@extends('layouts.app')

@section('page_title', 'Recurring expenses')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses"
        title="The expenses that come round again"
        subtitle="Rent, salaries and the internet line do not need discovering — they are known in advance, and a desk that retypes them every month eventually forgets one. A schedule here records nothing by itself: on its own day it produces an expense through the ordinary desk, approval limit and all."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses') }}">
                <i class="bi bi-receipt" aria-hidden="true"></i> The register
            </a>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expense-categories') }}">
                <i class="bi bi-diagram-3" aria-hidden="true"></i> Categories
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @if (session('status'))
        <div class="erp-note erp-note-ok mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('recurring')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    @if ($summary['due'] > 0)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-clock-history" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $summary['due'] }} schedule(s) have come due</strong>
                @if ($summary['overdue'])
                    The oldest is {{ $summary['overdue']->payee }} — {{ $summary['overdue']->rhythm() }}, due {{ $summary['overdue']->next_due_on?->toDateString() }}@if ($summary['overdue']->daysLate() > 0), {{ $summary['overdue']->daysLate() }} day(s) late @endif. The scheduled run does this every morning; a schedule refused there keeps its date, so nothing is skipped silently.
                @endif
            </div>
        </div>
    @else
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-calendar-check" aria-hidden="true"></i>
            <div>Nothing is due. Every schedule is waiting for its own date — the daily run will turn them into expenses when that date arrives.</div>
        </div>
    @endif

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Active schedules" :value="$summary['active']" icon="bi-arrow-repeat" :hint="$summary['paused'].' paused'" />
        <x-ui.kpi label="Due today or earlier" :value="$summary['due']" icon="bi-clock-history" hint="Nothing posts until the day arrives — and then the approval limit still applies" />
        <x-ui.kpi label="Generated this month" value="৳ {{ $summary['generated_this_month'] }}" icon="bi-journal-check" :hint="$summary['generated_count'].' expense(s) produced by a schedule'" />
        <x-ui.kpi
            label="Next to run"
            :value="$summary['next']?->next_due_on?->toDateString() ?? '—'"
            icon="bi-calendar-event"
            :hint="$summary['next']?->payee ?? 'no active schedule'" />
    </div>

    @if (! $schedules->isEmpty())
        <x-ui.table-shell title="Schedules" :count="$schedules->count().' schedule(s)'">
            <thead>
                <tr>
                    <th>What it is</th>
                    <th>Account it books to</th>
                    <th class="erp-th-num">Amount</th>
                    <th>Rhythm</th>
                    <th>Next due</th>
                    <th>State</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($schedules as $schedule)
                    <tr>
                        <td>
                            <form id="schedule-{{ $schedule->id }}" method="POST" action="{{ route('cash-bank.expenses.recurring.update', ['schedule' => $schedule->id]) }}">
                                @csrf
                                @method('PUT')
                                {{-- What describes the contract is not editable in a table
                                     row: a different payee, category or account is a
                                     different agreement, so it is a new schedule. The
                                     amount, the rhythm and the end date are what change. --}}
                                <input type="hidden" name="payee" value="{{ $schedule->payee }}">
                                <input type="hidden" name="category_id" value="{{ $schedule->category_id }}">
                                <input type="hidden" name="settled_with" value="{{ $schedule->settled_with }}">
                                <input type="hidden" name="money_account_id" value="{{ $schedule->money_account_id }}">
                                <input type="hidden" name="supplier_id" value="{{ $schedule->supplier_id }}">
                                <input type="hidden" name="currency" value="{{ $schedule->currency }}">
                                <input type="hidden" name="starts_on" value="{{ $schedule->starts_on?->toDateString() }}">
                                <input type="hidden" name="is_active" value="{{ $schedule->isActive() ? 1 : 0 }}">
                            </form>
                            <span class="erp-cell-strong">{{ $schedule->payee }}</span>
                            <span class="d-block erp-td-muted">
                                {{ $schedule->category?->name }} ·
                                @if ($schedule->settled_with === 'payable')
                                    owed to a supplier
                                @else
                                    paid from {{ $schedule->moneyAccount?->name ?? 'an account that is gone' }}
                                @endif
                            </span>
                            @if ($schedule->narration)
                                <span class="d-block erp-td-muted">{{ $schedule->narration }}</span>
                            @endif
                        </td>
                        <td class="erp-td-muted">{{ $schedule->category?->accountLabel() }}</td>
                        <td class="erp-td-num">
                            <input class="form-control form-control-sm erp-num text-end" type="number" step="0.01" min="0.01"
                                   name="amount" form="schedule-{{ $schedule->id }}" value="{{ number_format((float) $schedule->amount, 2, '.', '') }}"
                                   aria-label="Amount for {{ $schedule->payee }}">
                        </td>
                        <td>
                            <select class="form-select form-select-sm" name="frequency" form="schedule-{{ $schedule->id }}" aria-label="Frequency for {{ $schedule->payee }}">
                                @foreach ($frequencies as $value => $label)
                                    <option value="{{ $value }}" @selected($schedule->frequency === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                            <input class="form-control form-control-sm erp-num mt-1" type="number" min="1" max="31" step="1"
                                   name="day_of_month" form="schedule-{{ $schedule->id }}" value="{{ $schedule->day_of_month }}"
                                   placeholder="day" aria-label="Day of the month for {{ $schedule->payee }}">
                            <span class="d-block erp-td-muted mt-1">{{ $schedule->rhythm() }}</span>
                        </td>
                        <td>
                            <span class="erp-cell-strong">{{ $schedule->next_due_on?->toDateString() }}</span>
                            <span class="d-block erp-td-muted">
                                @if ($schedule->isDue())
                                    due — {{ $schedule->daysLate() }} day(s) late
                                @else
                                    {{ $schedule->last_generated_on ? 'last ran '.$schedule->last_generated_on->toDateString() : 'never generated yet' }}
                                @endif
                            </span>
                            <input class="form-control form-control-sm mt-1" type="date" name="ends_on"
                                   form="schedule-{{ $schedule->id }}" value="{{ $schedule->ends_on?->toDateString() }}"
                                   placeholder="no end date" aria-label="Last date for {{ $schedule->payee }}">
                        </td>
                        <td>
                            <x-ui.status :value="$schedule->isActive() ? 'active' : 'paused'" />
                            <span class="d-block erp-td-muted">{{ number_format((int) $schedule->generated_count) }} generated</span>
                            @if ($schedule->expenses_count > 0)
                                <span class="d-block"><a class="erp-td-muted" href="{{ route('cash-bank.expenses', ['q' => $schedule->payee]) }}">{{ number_format((int) $schedule->expenses_count) }} expense(s)</a></span>
                            @endif
                        </td>
                        <td class="erp-td-actions">
                            <button class="btn btn-sm btn-outline-secondary" type="submit" form="schedule-{{ $schedule->id }}">
                                <i class="bi bi-check2" aria-hidden="true"></i> Save
                            </button>
                            <form method="POST" action="{{ route('cash-bank.expenses.recurring.toggle', ['schedule' => $schedule->id]) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary" type="submit">
                                    @if ($schedule->isActive())
                                        <i class="bi bi-pause" aria-hidden="true"></i> Pause
                                    @else
                                        <i class="bi bi-play" aria-hidden="true"></i> Resume
                                    @endif
                                </button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
            @if ($mayGenerate)
                <x-slot:footer>
                    <form method="POST" action="{{ route('cash-bank.expenses.recurring.run') }}" class="d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 py-2"
                          data-confirm="Generate every schedule that is due? Each one posts through the ordinary expense desk, approval limit and all.">
                        @csrf
                        <span class="erp-td-muted">The scheduled run does this every morning at 06:20. This button is for when today's rent cannot wait for tomorrow.</span>
                        <button class="btn btn-outline-secondary btn-sm" type="submit" @disabled($summary['due'] === 0)>
                            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Generate what is due
                        </button>
                    </form>
                </x-slot:footer>
            @endif
        </x-ui.table-shell>

        <div class="erp-filter-note mt-2">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <span>A schedule never posts anything itself. On its day it produces an expense through the ordinary desk — so the approval limit applies, the category decides the account, and the person who created the schedule is the one who cannot approve what it generated.</span>
        </div>
    @else
        <div class="erp-card p-3">
            <x-ui.empty
                title="No expense is scheduled yet"
                icon="bi-arrow-repeat"
                text="Rent, salaries, the internet line: anything that repeats on a known day belongs here rather than in somebody's memory."
                action="Add the first schedule below"
                href="#add-schedule" />
        </div>
    @endif

    <section class="erp-card mt-3" id="add-schedule">
        <header class="erp-card-head">
            <div>
                <h2 class="erp-card-title">Schedule an expense</h2>
                <p class="erp-card-sub">The category decides which account it books to; the account below is where the money leaves from. Nothing is generated before the first date.</p>
            </div>
        </header>

        @if ($categories->isEmpty())
            <div class="p-3">
                <x-ui.empty
                    title="No expense category is configured yet"
                    icon="bi-diagram-3"
                    text="A category is the ledger account a scheduled expense books to. Configure one first — nothing here guesses an account."
                    :action="$perm('expenses.categories') ? 'Configure categories' : null"
                    :href="$perm('expenses.categories') ? route('cash-bank.expense-categories') : null" />
            </div>
        @else
            <form method="POST" action="{{ route('cash-bank.expenses.recurring.store') }}">
                @csrf
                <input type="hidden" name="is_active" value="0">
                <div class="erp-form-grid">
                    <div class="erp-form-field">
                        <label class="form-label" for="category_id">Category</label>
                        <select class="form-select @error('category_id') is-invalid @enderror" id="category_id" name="category_id" required>
                            <option value="">— what it is for —</option>
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" @selected((string) old('category_id') === (string) $category->id)>
                                    {{ $category->name }} — books to {{ $category->accountLabel() }}
                                </option>
                            @endforeach
                        </select>
                        @error('category_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="payee">Paid to</label>
                        <input class="form-control @error('payee') is-invalid @enderror" type="text" id="payee" name="payee"
                               value="{{ old('payee') }}" maxlength="160" required placeholder="Khan Properties">
                        @error('payee') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="amount">Amount each time</label>
                        <div class="erp-input-group">
                            <span class="input-group-text">৳</span>
                            <input class="form-control erp-num @error('amount') is-invalid @enderror" type="number" step="0.01" min="0.01"
                                   id="amount" name="amount" value="{{ old('amount') }}" required>
                        </div>
                        @error('amount') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="frequency">How often</label>
                        <select class="form-select @error('frequency') is-invalid @enderror" id="frequency" name="frequency" required>
                            @foreach ($frequencies as $value => $label)
                                <option value="{{ $value }}" @selected(old('frequency') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('frequency') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="day_of_month">Day of the month</label>
                        <input class="form-control erp-num @error('day_of_month') is-invalid @enderror" type="number" min="1" max="31" step="1"
                               id="day_of_month" name="day_of_month" value="{{ old('day_of_month') }}" placeholder="blank — the day it starts">
                        <div class="form-text">A month that is too short clamps to its last day: the 31st means the 28th in February and the 31st again in March.</div>
                        @error('day_of_month') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="starts_on">First on</label>
                        <input class="form-control @error('starts_on') is-invalid @enderror" type="date" id="starts_on" name="starts_on"
                               value="{{ old('starts_on', now()->toDateString()) }}" required>
                        <div class="form-text">The first run is this date itself, not one period later.</div>
                        @error('starts_on') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="ends_on">Last on (optional)</label>
                        <input class="form-control @error('ends_on') is-invalid @enderror" type="date" id="ends_on" name="ends_on" value="{{ old('ends_on') }}">
                        <div class="form-text">A lease with an end date stops itself rather than generating into a period nobody agreed to.</div>
                        @error('ends_on') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="settled_with">Paid, or owed?</label>
                        <select class="form-select @error('settled_with') is-invalid @enderror" id="settled_with" name="settled_with" required>
                            @foreach ($settledWith as $value => $label)
                                <option value="{{ $value }}" @selected(old('settled_with', 'money') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('settled_with') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="money_account_id">Paid from</label>
                        <select class="form-select @error('money_account_id') is-invalid @enderror" id="money_account_id" name="money_account_id">
                            <option value="">— required when it is paid —</option>
                            @foreach ($moneyAccounts as $account)
                                <option value="{{ $account->id }}" @selected((string) old('money_account_id') === (string) $account->id)>
                                    {{ $account->code }} — {{ $account->name }}
                                </option>
                            @endforeach
                        </select>
                        @error('money_account_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <label class="form-label" for="supplier_id">Owed to (optional)</label>
                        <select class="form-select @error('supplier_id') is-invalid @enderror" id="supplier_id" name="supplier_id">
                            <option value="">— no supplier on the books —</option>
                            @foreach ($suppliers as $supplier)
                                <option value="{{ $supplier->id }}" @selected((string) old('supplier_id') === (string) $supplier->id)>{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                        @error('supplier_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field erp-form-field-wide">
                        <label class="form-label" for="narration">What it is</label>
                        <input class="form-control @error('narration') is-invalid @enderror" type="text" id="narration" name="narration"
                               value="{{ old('narration') }}" maxlength="500" placeholder="Monthly rent — Uttara warehouse">
                        @error('narration') <div class="text-danger small">{{ $message }}</div> @enderror
                    </div>

                    <div class="erp-form-field">
                        <span class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" value="1" id="is_active" name="is_active" @checked(old('is_active', true))>
                            <label class="form-check-label" for="is_active">Start it immediately</label>
                        </span>
                        <div class="form-text">Switched off, the schedule is kept and fires nothing — the same state as pausing it later.</div>
                    </div>
                </div>

                <div class="erp-card-tight d-flex flex-wrap justify-content-between align-items-center gap-2 px-3 pb-3">
                    <span class="erp-td-muted">Every generated expense is an ordinary expense: it can be approved, refused or reversed like any other, and it carries the schedule that produced it.</span>
                    <button class="btn btn-primary" type="submit">
                        <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Schedule it
                    </button>
                </div>
            </form>
        @endif
    </section>
@endsection
