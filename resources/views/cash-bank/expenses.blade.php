@extends('layouts.app')

@section('page_title', 'Expenses')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Expenses"
        title="What the company spent"
        subtitle="Every expense here names what it was for and where the money went — a category that is a real ledger account, and either the account the money left or the supplier it is owed to. Above the approval limit nothing posts until somebody signs it off."
        :pin="true">
        <x-slot:actions>
            @if ($perm('expenses.create'))
                <a class="btn btn-primary" href="{{ route('cash-bank.expenses.create') }}">
                    <i class="bi bi-plus-lg" aria-hidden="true"></i> Add expense
                </a>
            @endif
            @if ($perm('expenses.recurring'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expenses.recurring') }}">
                    <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Recurring
                    @if ($recurringDue > 0)
                        <span class="erp-chip erp-chip-warn ms-1">{{ $recurringDue }} due</span>
                    @endif
                </a>
            @endif
            @if ($perm('expenses.categories'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.expense-categories') }}">
                    <i class="bi bi-diagram-3" aria-hidden="true"></i> Categories
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

    @error('expense')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Posted in this window"
            value="৳ {{ $summary['posted'] }}"
            icon="bi-journal-check"
            :hint="$summary['posted_count'].' expense(s) between '.$from.' and '.$to" />
        <x-ui.kpi
            label="Waiting for a signature"
            value="৳ {{ $summary['pending'] }}"
            icon="bi-hourglass-split"
            :hint="$summary['pending_count'].' expense(s) — and none of them has touched the ledger yet'" />
        <x-ui.kpi
            label="Approval limit"
            value="৳ {{ $summary['threshold'] }}"
            icon="bi-sliders"
            hint="At or above this an expense waits; below it, it posts as it is recorded" />
        <x-ui.kpi
            label="Given back this window"
            value="৳ {{ $summary['reversed'] }}"
            icon="bi-arrow-counterclockwise"
            :hint="$summary['rejected_count'].' refused before posting'; the figure above was posted and then reversed'" />
    </div>

    @if ($summary['pending_count'] > 0 && $perm('expenses.approve'))
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $summary['pending_count'] }} expense(s) are waiting on this desk</strong>
                They are worth ৳ {{ $summary['pending'] }} and none of it is in the books. The person who recorded an expense cannot approve it, so somebody else has to look at it.
            </div>
        </div>
    @endif

    @if ($recurringDue > 0 && $perm('expenses.recurring'))
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">{{ $recurringDue }} standing expense(s) are due</strong>
                @if ($perm('expenses.create'))
                    The daily run turns them into ordinary expenses on their own day — or say so now on the <a href="{{ route('cash-bank.expenses.recurring') }}">recurring desk</a>. Nothing has been generated for them yet.
                @else
                    They will be generated on their own day and then wait for a signature like any other expense.
                @endif
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.expenses') }}">
        <div class="erp-filter">
            <label class="form-label" for="status">State</label>
            <select class="form-select" id="status" name="status" data-erp-autosubmit>
                <option value="">Everything</option>
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}" @selected($filters['status'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="category">Category</label>
            <select class="form-select" id="category" name="category" data-erp-autosubmit>
                <option value="">Every category</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="settled_with">Money</label>
            <select class="form-select" id="settled_with" name="settled_with" data-erp-autosubmit>
                <option value="">Paid and owed</option>
                @foreach ($settledWith as $value => $label)
                    <option value="{{ $value }}" @selected($filters['settled_with'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] ?? $from }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] ?? $to }}">
        </div>
        <div class="erp-filter erp-filter-wide">
            <label class="form-label" for="q">Find</label>
            <input class="form-control" type="search" id="q" name="q" data-erp-search value="{{ $filters['q'] }}" placeholder="Number, payee or narration">
        </div>
        <div class="erp-filterbar-actions">
            <a class="btn btn-link" href="{{ route('cash-bank.expenses') }}">Reset</a>
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    @if ($expenses->isEmpty())
        <div class="erp-card p-3">
            <x-ui.empty
                title="Nothing has been recorded against this filter"
                icon="bi-receipt"
                text="An expense is recorded with the category that tells the ledger where it belongs and the account the money left."
                :action="$perm('expenses.create') ? 'Add expense' : null"
                :href="$perm('expenses.create') ? route('cash-bank.expenses.create') : null" />
        </div>
    @else
        <x-ui.table-shell title="The expense register" :count="$expenses->count().' expense(s)'">
            <thead>
                <tr>
                    <th>Expense</th>
                    <th>Category</th>
                    <th>Payee</th>
                    <th>Money</th>
                    <th class="erp-th-num">Amount</th>
                    <th>State</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($expenses as $expense)
                    <tr data-erp-row-href="{{ route('cash-bank.expenses.show', ['expense' => $expense->id]) }}">
                        <td>
                            <span class="erp-cell-strong font-monospace">{{ $expense->expense_no }}</span>
                            <span class="d-block erp-td-muted">{{ $expense->expense_date?->toDateString() }}</span>
                            @if ($expense->isGenerated())
                                <span class="erp-chip erp-chip-outline" title="Generated from a recurring schedule on its due date">scheduled</span>
                            @endif
                        </td>
                        <td>
                            {{ $expense->category?->name }}
                            <span class="d-block erp-td-muted">{{ $expense->category?->accountLabel() }}</span>
                        </td>
                        <td>
                            {{ $expense->payee }}
                            @if ($expense->supplier)
                                <span class="d-block erp-td-muted">{{ $expense->supplier->name }}</span>
                            @endif
                        </td>
                        <td>
                            <x-ui.status :value="$expense->settlementTone()" :label="$expense->settlementLabel()" />
                            @if ($expense->settled_with === 'money' && $expense->moneyAccount)
                                <span class="d-block erp-td-muted">{{ $expense->moneyAccount->name }}</span>
                            @endif
                        </td>
                        <td class="erp-td-num">
                            <span class="{{ $expense->settled_with === 'payable' ? 'erp-money-out' : 'erp-money-flat' }}">
                                {{ number_format((float) $expense->amount, 2) }}
                            </span>
                        </td>
                        <td>
                            <x-ui.status :value="$expense->statusTone()" :label="$expense->label()" />
                            @if ($expense->journalEntry)
                                <span class="d-block erp-td-muted font-monospace">{{ $expense->journalEntry->entry_no }}</span>
                            @elseif ($expense->approval_gate)
                                <span class="d-block erp-td-muted">limit ৳ {{ number_format((float) $expense->approval_threshold, 2) }}</span>
                            @endif
                        </td>
                        <td class="erp-td-actions">
                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('cash-bank.expenses.show', ['expense' => $expense->id]) }}">
                                <i class="bi bi-eye" aria-hidden="true"></i> Open
                            </a>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif

    <div class="erp-split mt-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Where it went</h2>
                    <p class="erp-card-sub">Posted expenses between {{ $from }} and {{ $to }}, by the account they were booked to.</p>
                </div>
            </header>
            @if ($byCategory->isEmpty())
                <div class="p-3 erp-td-muted">Nothing has posted in this window yet.</div>
            @else
                <div class="erp-table-scroll">
                    <table class="erp-table erp-table-compact">
                        <thead>
                            <tr>
                                <th>Category</th>
                                <th>Account</th>
                                <th class="erp-th-num">Expenses</th>
                                <th class="erp-th-num">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byCategory as $row)
                                <tr>
                                    <td class="erp-cell-strong">{{ $row->category }}</td>
                                    <td class="erp-td-muted"><code>{{ $row->account_code }}</code> {{ $row->account_name }}</td>
                                    <td class="erp-td-num">{{ number_format((int) $row->rows) }}</td>
                                    <td class="erp-td-num erp-num">{{ number_format((float) $row->total, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Owed, not paid <span class="erp-chip erp-chip-outline">{{ $unpaid->count() }}</span></h2>
                </header>
                @if ($unpaid->isEmpty())
                    <div class="p-3 erp-td-muted">Nothing is owed. Every recorded expense has been paid from an account.</div>
                @else
                    <ul class="erp-list px-3 pb-3">
                        @foreach ($unpaid as $owed)
                            <li class="d-flex justify-content-between align-items-start gap-2 border-top py-2">
                                <span>
                                    <span class="erp-cell-strong">{{ $owed->payee }}</span>
                                    <span class="d-block erp-td-muted">{{ $owed->expense_date?->toDateString() }} · {{ $owed->category?->name }}</span>
                                    @unless ($owed->isPosted())
                                        <span class="d-block"><x-ui.status :value="$owed->statusTone()" :label="$owed->label()" /></span>
                                    @endunless
                                </span>
                                <span class="erp-money-out">{{ number_format((float) $owed->amount, 2) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>

            @if ($summary['threshold'] !== '0.00')
                <div class="erp-card mt-3">
                    <header class="erp-card-head"><h2 class="erp-card-title">The approval limit</h2></header>
                    <div class="p-3">
                        <p class="mb-2">An expense worth <strong>৳ {{ $summary['threshold'] }}</strong> or more waits for approval before it posts. Below it, the expense posts the moment it is recorded.</p>
                        <p class="erp-td-muted mb-0">The limit is a setting, and the number an expense was judged against is written on the expense itself — so raising the limit later never rewrites what happened.</p>
                    </div>
                </div>
            @endif
        </aside>
    </div>
@endsection
