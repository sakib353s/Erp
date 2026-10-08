@extends('layouts.app')

@section('page_title', 'Petty cash expenses')

@section('content')
    <x-ui.page-header
        eyebrow="Cash &amp; Bank · Petty cash · Expenses"
        title="What came out of the float"
        subtitle="Every voucher here is a real payment: the money left the float's own account and the ledger knows about it — debited to the category it was spent on, credited to the tin. The float cannot pay more than it holds, which is why the register and the ledger always agree."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.petty-cash') }}">
                <i class="bi bi-cash-coin" aria-hidden="true"></i> The floats
            </a>
            @if ($perm('pettycash.replenish'))
                <a class="btn btn-primary" href="{{ route('cash-bank.petty-cash.replenishments') }}">
                    <i class="bi bi-arrow-down-up" aria-hidden="true"></i> Replenish
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
            label="Paid out this month"
            value="৳ {{ $summary['paid_this_month'] }}"
            icon="bi-receipt"
            :hint="$summary['paid_count'].' voucher(s) out of the floats'" />
        <x-ui.kpi
            label="In the tins now"
            value="৳ {{ $summary['float'] }}"
            icon="bi-cash-stack"
            :hint="'Across '.$summary['active'].' open float(s) of '.$summary['funds']" />
        <x-ui.kpi
            label="Short of their level"
            value="৳ {{ $summary['shortfall'] }}"
            icon="bi-arrow-down-up"
            hint="What a replenishment would put back" />
        <x-ui.kpi
            label="Put back this month"
            value="৳ {{ $summary['replenished_this_month'] }}"
            icon="bi-arrow-repeat"
            hint="Transfers into the floats, never a second record of the spending" />
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.petty-cash.expenses') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="fund">Float</label>
            <select class="form-select" id="fund" name="fund">
                <option value="">Every float</option>
                @foreach ($funds as $item)
                    <option value="{{ $item->id }}" @selected($filters['fund'] === $item->id)>{{ $item->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter">
            <label class="form-label" for="category">Category</label>
            <select class="form-select" id="category" name="category">
                <option value="">Every category</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" @selected($filters['category'] === $category->id)>{{ $category->name }}</option>
                @endforeach
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
        <div class="erp-filterbar-actions">
            @if (array_filter($filters, fn ($value) => $value !== null))
                <a class="btn btn-link" href="{{ route('cash-bank.petty-cash.expenses') }}">Reset</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Filter</button>
        </div>
    </form>

    <x-ui.table-shell title="Vouchers paid out of the floats" :count="$vouchers->count().' voucher(s) shown'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Voucher</th>
                <th>Float</th>
                <th>Payee</th>
                <th>Spent on</th>
                <th class="erp-th-num">Amount</th>
                <th>Recorded by</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($vouchers as $voucher)
                <tr>
                    <td>{{ $voucher->occurred_on->toDateString() }}</td>
                    <td>
                        <span class="erp-cell-strong">{{ $voucher->documentNo() ?? '—' }}</span>
                        @if ($voucher->payment?->journal_entry_id)
                            <div class="erp-td-muted">posted to the ledger</div>
                        @endif
                    </td>
                    <td>
                        {{ $voucher->fund?->name }}
                        @if ($voucher->fund)
                            <div class="erp-td-muted">
                                <a href="{{ route('cash-bank.book', $voucher->fund->account_id) }}">the tin's ledger</a>
                            </div>
                        @endif
                    </td>
                    <td>
                        {{ $voucher->payee ?? '—' }}
                        @if ($voucher->narration)
                            <div class="erp-td-muted">{{ $voucher->narration }}</div>
                        @endif
                    </td>
                    <td>
                        {{ $voucher->category?->name ?? '—' }}
                        <div class="erp-td-muted">{{ $voucher->category?->accountLabel() }}</div>
                    </td>
                    <td class="erp-td-num">৳ {{ number_format((float) $voucher->amount, 2) }}</td>
                    <td>
                        {{ $voucher->creator?->name ?? '—' }}
                        @if ($voucher->request)
                            <div class="erp-td-muted">against request #{{ $voucher->request_id }}, approved by {{ $voucher->request->decider?->name ?? '—' }}</div>
                        @else
                            <div class="erp-td-muted">below the limit of {{ $threshold }}</div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty
                            title="Nothing has been paid out of a float yet"
                            icon="bi-receipt"
                            text="Vouchers appear here the moment they are paid — a rickshaw fare, a courier, a padlock. Each one is a payment out of the float's own account." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($mayPay && $funds->isNotEmpty() && $categories->isNotEmpty())
        <section class="erp-card mt-3">
            <header class="erp-card-head">
                <div>
                    <h2 class="erp-card-title">Pay a voucher out of a float</h2>
                    <p class="erp-card-sub">
                        The float has to be holding the money — the desk checks the ledger before it lets the voucher through,
                        because a custodian cannot hand over cash the tin does not have.
                    </p>
                </div>
            </header>
            <div>
                <form method="POST" action="{{ route('cash-bank.petty-cash.expenses.store') }}">
                    @csrf
                    <div class="erp-form-grid">
                        <div class="erp-form-field">
                            <label class="form-label" for="fund_id">Float</label>
                            <select class="form-select" id="fund_id" name="fund_id" required>
                                @foreach ($funds as $item)
                                    <option value="{{ $item->id }}" @selected(old('fund_id', $filters['fund']) == $item->id)>
                                        {{ $item->code }} — {{ $item->name }}
                                    </option>
                                @endforeach
                            </select>
                            @error('fund_id')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="expense_category_id">Spent on</label>
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
                            <input class="form-control" id="payee" name="payee" value="{{ old('payee') }}" maxlength="160" placeholder="Who was handed the cash" required>
                            @error('payee')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="amount">Amount</label>
                            <input class="form-control" id="amount" name="amount" inputmode="decimal" value="{{ old('amount') }}" required>
                            @error('amount')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field">
                            <label class="form-label" for="spent_on">Date paid</label>
                            <input class="form-control" id="spent_on" name="spent_on" type="date" value="{{ old('spent_on', now()->toDateString()) }}" required>
                            @error('spent_on')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                        <div class="erp-form-field erp-form-field-wide">
                            <label class="form-label" for="narration">What it was for</label>
                            <input class="form-control" id="narration" name="narration" value="{{ old('narration') }}" maxlength="300" placeholder="Two rickshaws to the courier office">
                            @error('narration')<div class="erp-field-error">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <button class="btn btn-primary mt-2" type="submit">
                        <i class="bi bi-cash-coin" aria-hidden="true"></i> Pay and record the voucher
                    </button>
                </form>
            </div>
        </section>
    @elseif ($funds->isEmpty())
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                No open float, so there is nothing to pay out of.
                @if ($perm('pettycash.funds'))
                    <a href="{{ route('cash-bank.petty-cash') }}">Declare one first</a>.
                @endif
            </div>
        </div>
    @endif

    <x-ui.related-pages />
@endsection
