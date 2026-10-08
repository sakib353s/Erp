@extends('layouts.app')

@section('page_title', 'Cash & bank')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Cash management"
        title="Where the money is"
        subtitle="Every figure on this page is the sum of posted journal lines on the account — a drawer is not a spreadsheet of its own, it is an account in the books that money can sit in. Count the till, call the bank, and if the two disagree the disagreement is a movement nobody has entered here yet."
        :pin="true">
        <x-slot:actions>
            @if ($perm('cash.receipts.create'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.receipts') }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Record a receipt
                </a>
            @endif
            @if ($perm('cash.payments.create'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.payments') }}">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Record a payment
                </a>
            @endif
            @if ($perm('cash.transfers'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.transfer') }}">
                    <i class="bi bi-arrow-left-right" aria-hidden="true"></i> Move money
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
        <div class="erp-note erp-note-info mb-3">
            <i class="bi bi-check2-circle" aria-hidden="true"></i>
            <div>{{ session('status') }}</div>
        </div>
    @endif

    @error('cash_bank')
        <div class="erp-note erp-note-danger mb-3">
            <i class="bi bi-exclamation-octagon" aria-hidden="true"></i>
            <div>{{ $message }}</div>
        </div>
    @enderror

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Cash in hand" :value="'৳ '.number_format((float) $totals['cash'], 2)" icon="bi-cash-stack"
                  hint="Tills and cash drawers, from the books" />
        <x-ui.kpi label="In the bank" :value="'৳ '.number_format((float) $totals['bank'], 2)" icon="bi-bank"
                  hint="Every bank account added up" />
        <x-ui.kpi label="Mobile wallets" :value="'৳ '.number_format((float) $totals['wallet'], 2)" icon="bi-phone"
                  hint="bKash, Nagad, Rocket and Upay balances" />
        <x-ui.kpi label="Money in the company" :value="'৳ '.number_format((float) $totals['total'], 2)" icon="bi-wallet2"
                  hint="The three totals above, added up" />
    </div>

    @if (! $accountsConfigured)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>
            <div>
                <strong class="d-block mb-1">No money account is set up beyond the standard chart</strong>
                The chart ships with <span class="font-monospace">1110 Cash in Hand</span> and
                <span class="font-monospace">1120 Bank Account</span>. Real operations need the accounts money actually
                moves through — the drawer at each counter, each bank, each mobile wallet — so that a statement can be
                matched to one of them.
                @if ($perm('bank.accounts'))
                    <a class="fw-semibold" href="{{ route('cash-bank.accounts') }}">Declare the first one</a>.
                @endif
            </div>
        </div>
    @endif

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.index') }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="branch">Branch</label>
            <select class="form-select" id="branch" name="branch" data-erp-autosubmit>
                <option value="all" @selected($filters['branch'] === null)>Every branch</option>
                @foreach ($branches as $branch)
                    <option value="{{ $branch->id }}" @selected($filters['branch'] === $branch->id)>
                        {{ $branch->name }}@if ($branch->is_default) (head office)@endif
                    </option>
                @endforeach
            </select>
        </div>
        <div class="erp-filter-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            A branch sees the accounts its movements were posted in. Money entered at head office and spent at a
            counter is one position until somebody says otherwise.
        </div>
    </form>

    <x-ui.table-shell :title="'Money accounts'" :count="count($positions).' account(s)'">
        <thead>
            <tr>
                <th>Account</th>
                <th>Kind</th>
                <th class="erp-th-num">Debits</th>
                <th class="erp-th-num">Credits</th>
                <th class="erp-th-num">Balance</th>
                <th>Last movement</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            @forelse ($positions as $row)
                <tr>
                    <td data-label="Account">
                        <span class="erp-cell-strong">{{ $row['account']->name }}</span>
                        <span class="d-block erp-td-muted font-monospace">{{ $row['account']->code }}</span>
                    </td>
                    <td data-label="Kind">
                        {{ $row['label'] }}
                        @unless ($row['account']->is_active)
                            <span class="d-block erp-td-muted">closed to new movement</span>
                        @endunless
                    </td>
                    <td data-label="Debits" class="erp-td-num erp-td-muted">{{ number_format((float) $row['debit'], 2) }}</td>
                    <td data-label="Credits" class="erp-td-num erp-td-muted">{{ number_format((float) $row['credit'], 2) }}</td>
                    <td data-label="Balance" class="erp-td-num">
                        <span class="erp-cell-strong @if ($row['is_negative']) erp-money-out @endif">
                            ৳ {{ number_format((float) $row['balance'], 2) }}
                        </span>
                    </td>
                    <td data-label="Last movement" class="erp-td-muted">
                        @if ($row['movements'] === 0)
                            <span>never used</span>
                        @else
                            {{ $row['last_movement_on'] }}
                            <span class="d-block erp-td-muted">{{ number_format($row['movements']) }} line(s) in the ledger</span>
                        @endif
                    </td>
                    <td data-label="" class="erp-td-actions">
                        @if ($perm('bank.view'))
                            <a class="btn btn-sm btn-outline-secondary"
                               href="{{ route('cash-bank.book', ['account' => $row['account']->id]) }}">
                                <i class="bi bi-journal-text" aria-hidden="true"></i> Book
                            </a>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7">
                        <x-ui.empty icon="bi-wallet2" title="No money account is declared yet"
                                    text="Declare the drawer, the bank accounts and the wallets money moves through — each one becomes a leaf in the chart of accounts." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    @if ($totals['negative'] > 0)
        <div class="erp-note erp-note-warn mt-3">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
            <div>
                {{ $totals['negative'] }} account(s) stand below zero. Cash cannot go negative and a bank rarely should:
                the usual cause is a payment entered into the wrong account, or a deposit entered twice.
            </div>
        </div>
    @endif

    <div class="erp-split mt-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Money in and out, last 14 days</h2>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">taken from the movement documents</span>
                </div>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr>
                            <th>Day</th>
                            <th class="erp-th-num">In</th>
                            <th class="erp-th-num">Out</th>
                            <th class="erp-th-num">Net</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($movement, true) as $day => $net)
                            @php($delta = $net['in'] - $net['out'])
                            <tr>
                                <td data-label="Day" class="erp-td-muted">{{ \Illuminate\Support\Carbon::parse($day)->format('d M') }}</td>
                                <td data-label="In" class="erp-td-num">{{ $net['in'] > 0 ? number_format($net['in'], 2) : '—' }}</td>
                                <td data-label="Out" class="erp-td-num">{{ $net['out'] > 0 ? number_format($net['out'], 2) : '—' }}</td>
                                <td data-label="Net" class="erp-td-num">
                                    <span class="erp-cell-strong @if ($delta < 0) erp-money-out @endif">
                                        {{ number_format($delta, 2) }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <aside>
            <div class="erp-card">
                <header class="erp-card-head">
                    <h2 class="erp-card-title">Latest movements</h2>
                </header>
                <div class="erp-dl erp-dl-tight erp-dl-striped">
                    @foreach ($recentReceipts as $receipt)
                        <dt>{{ $receipt->receipt_no }}</dt>
                        <dd>
                            <span class="erp-money-in">+ {{ number_format((float) $receipt->amount, 2) }}</span>
                            into {{ $receipt->account?->name }}
                            <span class="d-block erp-td-muted">{{ $receipt->paid_at?->format('d M Y') }} · {{ $receipt->customer?->name ?? $receipt->narration ?? 'no payer named' }}</span>
                        </dd>
                    @endforeach
                    @foreach ($recentPayments as $payment)
                        <dt>{{ $payment->receipt_no }}</dt>
                        <dd>
                            <span class="erp-money-out">− {{ number_format((float) $payment->amount, 2) }}</span>
                            from {{ $payment->account?->name }}
                            <span class="d-block erp-td-muted">{{ $payment->paid_at?->format('d M Y') }} · {{ $payment->supplier?->name ?? $payment->narration ?? 'no payee named' }}</span>
                        </dd>
                    @endforeach
                    @foreach ($recentTransfers as $transfer)
                        <dt>{{ $transfer->transfer_no }}</dt>
                        <dd>
                            {{ number_format((float) $transfer->amount, 2) }} moved
                            <span class="d-block erp-td-muted">
                                {{ $transfer->transferred_on?->format('d M Y') }} ·
                                {{ $transfer->fromAccount?->name }} → {{ $transfer->toAccount?->name }}
                            </span>
                        </dd>
                    @endforeach
                    @if ($recentReceipts->isEmpty() && $recentPayments->isEmpty() && $recentTransfers->isEmpty())
                        <dt>Nothing yet</dt>
                        <dd>No money has moved through this company's accounts since the books opened.</dd>
                    @endif
                </div>
            </div>
        </aside>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
