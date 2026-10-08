@extends('layouts.app')

@section('page_title', 'Petty cash requests')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Petty cash · Requests"
        title="Money asked for before it is spent"
        subtitle="Above the company's limit nothing comes out of a float until somebody asks and somebody else agrees. A request is not a voucher: while it waits there is no payment, no number and nothing in the ledger — so a waiting request can never be mistaken for money that has moved."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.petty-cash') }}">
                <i class="bi bi-cash-coin" aria-hidden="true"></i> The floats
            </a>
            @if ($perm('pettycash.spend'))
                <a class="btn btn-primary" href="{{ route('cash-bank.petty-cash.expenses') }}">
                    <i class="bi bi-receipt" aria-hidden="true"></i> Vouchers
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

    @error('petty_cash')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi
            label="Waiting for an answer"
            value="৳ {{ $summary['pending_value'] }}"
            icon="bi-hourglass-split"
            :hint="$summary['pending'].' request(s) — none of them has reached the ledger yet'" />
        <x-ui.kpi
            label="Paid out of the floats this month"
            value="৳ {{ $summary['paid_this_month'] }}"
            icon="bi-receipt"
            :hint="$summary['paid_count'].' voucher(s), each through the payment book'" />
        <x-ui.kpi
            label="Approval limit"
            value="৳ {{ $threshold }}"
            icon="bi-sliders"
            hint="At or above this a voucher is asked for; below it the custodian pays it" />
        <x-ui.kpi
            label="In the tins now"
            value="৳ {{ $summary['float'] }}"
            icon="bi-cash-stack"
            :hint="$summary['active'].' open float(s); ৳ '.$summary['shortfall'].' short of their level'" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.petty-cash.requests') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="status">State</label>
            <select class="form-select" id="status" name="status">
                <option value="">Everything</option>
                @foreach ($statuses as $key => $label)
                    <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="fund">Float</label>
            <select class="form-select" id="fund" name="fund">
                <option value="">Every float</option>
                @foreach ($funds as $fund)
                    <option value="{{ $fund->id }}" @selected($filters['fund'] === $fund->id)>{{ $fund->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filterbar-actions">
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a class="btn btn-link" href="{{ route('cash-bank.petty-cash.requests') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="What people have asked for" :count="$requests->count().' request(s) shown'">
        <thead>
            <tr>
                <th>Needed on</th>
                <th>Float</th>
                <th>Payee</th>
                <th>Category</th>
                <th class="erp-th-num">Amount</th>
                <th>Asked by</th>
                <th>State</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($requests as $pettyRequest)
                <tr>
                    <td>{{ $pettyRequest->needed_on->toDateString() }}</td>
                    <td>
                        <span class="erp-cell-strong">{{ $pettyRequest->fund?->name }}</span>
                        <div class="erp-td-muted">{{ $pettyRequest->fund?->code }}</div>
                    </td>
                    <td>
                        {{ $pettyRequest->payee }}
                        @if ($pettyRequest->narration)
                            <div class="erp-td-muted">{{ $pettyRequest->narration }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $pettyRequest->category?->name }}
                        <div class="erp-td-muted">{{ $pettyRequest->category?->accountLabel() }}</div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $pettyRequest->amount, 2) }}</td>
                    <td>
                        {{ $pettyRequest->requester?->name ?? '—' }}
                        <div class="erp-td-muted">{{ $pettyRequest->created_at?->toDateString() }}</div>
                    </td>
                    <td>
                        <x-ui.status :value="$pettyRequest->statusTone()" :label="$pettyRequest->label()" />
                        @if ($pettyRequest->decider)
                            <div class="erp-td-muted">by {{ $pettyRequest->decider->name }}</div>
                        @endif
                    </td>
                    <td class="erp-td-actions">
                        @if ($pettyRequest->isPending() && $mayDecide)
                            <form method="POST" action="{{ route('cash-bank.petty-cash.requests.decide', $pettyRequest) }}">
                                @csrf
                                <input class="form-control form-control-sm mb-2" name="note" maxlength="300" placeholder="Why (kept with the decision)">
                                <div class="d-flex gap-2">
                                    <button class="btn btn-sm btn-primary" type="submit" name="action" value="approve">Pay it</button>
                                    <button class="btn btn-sm btn-outline-secondary" type="submit" name="action" value="reject">Refuse</button>
                                </div>
                            </form>
                        @elseif ($pettyRequest->isPending())
                            <span class="erp-td-muted">Waiting for somebody who can approve it</span>
                        @elseif ($pettyRequest->isApproved() && $pettyRequest->payment)
                            <span class="erp-cell-strong">{{ $pettyRequest->payment->receipt_no }}</span>
                            <div class="erp-td-muted">paid out</div>
                        @else
                            <span class="erp-td-muted">{{ $pettyRequest->decision_note ?? 'No reason given' }}</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8">
                        <x-ui.empty
                            title="Nothing is waiting for an answer"
                            icon="bi-question-circle"
                            text="Requests appear here when a voucher is at or above the company's limit. Below it the custodian pays straight away — see the voucher register instead." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($perm('pettycash.spend') && $funds->isNotEmpty() && $categories->isNotEmpty())
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Ask for money out of a float</h2>
                    <p class="erp-card-sub">
                        @if ((float) $threshold > 0)
                            At or above ৳ {{ $threshold }} this waits for somebody else's answer. Below it, the same form pays
                            the money and records the voucher in one step.
                        @else
                            Every voucher is paid as it is asked for, because no limit is set for petty cash.
                        @endif
                    </p>
                </div>
            </header>
            <div>
                <form method="POST" action="{{ route('cash-bank.petty-cash.requests.store') }}">
                    @csrf
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="fund_id">Float</label>
                            <select class="form-select" id="fund_id" name="fund_id" required>
                                @foreach ($funds as $fund)
                                    <option value="{{ $fund->id }}" @selected(old('fund_id') == $fund->id)>
                                        {{ $fund->code }} — {{ $fund->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('fund_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="expense_category_id">What it is for</label>
                            <select class="form-select" id="expense_category_id" name="expense_category_id" required>
                                @foreach ($categories as $category)
                                    <option value="{{ $category->id }}" @selected(old('expense_category_id') == $category->id)>
                                        {{ $category->name }} ({{ $category->account?->code }})
                                    </option>
                                @endforeach
                            </select>
                            @error('expense_category_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="payee">Payee</label>
                            <input class="form-control" id="payee" name="payee" value="{{ old('payee') }}" maxlength="160" placeholder="Who is to be paid" required>
                            @error('payee')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="amount">Amount</label>
                            <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
                            @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="needed_on">Needed on</label>
                            <input class="form-control" id="needed_on" name="needed_on" type="date" value="{{ old('needed_on', now()->toDateString()) }}" required>
                            @error('needed_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="narration">What it is</label>
                            <input class="form-control" id="narration" name="narration" value="{{ old('narration') }}" maxlength="300" placeholder="Courier to Uttara — three parcels">
                            @error('narration')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <p class="form-text">
                        The money leaves the float's own account, so the float has to be holding it: a custodian cannot hand
                        over cash the tin does not have.
                    </p>
                    <button class="btn btn-primary mt-2" type="submit">
                        <i class="bi bi-send" aria-hidden="true"></i> Send the request
                    </button>
                </form>
            </div>
        </section>
    @elseif ($funds->isEmpty())
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                No float is open, so there is nothing to ask money out of.
                @if ($perm('pettycash.funds'))
                    <a href="{{ route('cash-bank.petty-cash') }}">Declare one first</a>.
                @endif
            </div>
        </div>
    @elseif ($categories->isEmpty())
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                No expense category is configured, and a voucher has to be filed against one — that is what tells the
                ledger which account the money was spent on.
                @if ($perm('expenses.categories'))
                    <a href="{{ route('cash-bank.expense-categories') }}">Configure the categories</a>.
                @endif
            </div>
        </div>
    @endif

    @if ($recent->isNotEmpty())
        <x-ui.table-shell class="mt-3" title="Decided lately" :count="$recent->count().' request(s)'">
            <thead>
                <tr>
                    <th>Decided</th>
                    <th>Payee</th>
                    <th class="erp-th-num">Amount</th>
                    <th>Answer</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($recent as $pettyRequest)
                    <tr>
                        <td>{{ $pettyRequest->decided_at?->toDateString() ?? '—' }}</td>
                        <td>{{ $pettyRequest->payee }}</td>
                        <td class="erp-td-num">৳ {{ number_format((float) $pettyRequest->amount, 2) }}</td>
                        <td><x-ui.status :value="$pettyRequest->statusTone()" :label="$pettyRequest->label()" /></td>
                        <td>{{ $pettyRequest->decider?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </x-ui.table-shell>
    @endif

    <x-ui.related-pages />
@endsection
