@extends('layouts.app')

@section('page_title', 'Cash payments')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Cash payments"
        title="Money out"
        subtitle="Every payment names the account it left and the account it is against, and posts as an expense voucher. Rent, courier bills, an advance, a supplier payment no single bill owns — the desk records what the ledger has to see, and says which account the cost landed under."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
            </a>
            @if ($perm('cash.receipts.create'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.receipts') }}">
                    <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Money in
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

    @if ($moneyAccounts->isEmpty())
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>
            <div>
                No money account is declared, so there is no account money could leave.
                @if ($perm('bank.accounts'))
                    <a class="fw-semibold" href="{{ route('cash-bank.accounts') }}">Declare one first</a>.
                @endif
            </div>
        </div>
    @else
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Record a payment</h2>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $moneyAccounts->count() }} account(s) money can leave</span>
                </div>
            </header>

            @include('cash-bank.partials.movement-form', [
                'direction' => 'out',
                'moneyAccounts' => $moneyAccounts,
                'counterAccounts' => $counterAccounts,
                'customers' => collect(),
                'suppliers' => $suppliers,
            ])
        </section>
    @endif

    <div class="erp-split mt-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Money paid out, last 14 days</h2>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr><th>Day</th><th class="erp-th-num">Paid out</th></tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($window, true) as $day => $net)
                            <tr>
                                <td data-label="Day" class="erp-td-muted">{{ \Illuminate\Support\Carbon::parse($day)->format('d M') }}</td>
                                <td data-label="Paid out" class="erp-td-num">
                                    <span class="erp-money-out">{{ $net['out'] > 0 ? number_format($net['out'], 2) : '—' }}</span>
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
                    <h2 class="erp-card-title">Today at a glance</h2>
                </header>
                @php($today = $window[now()->toDateString()] ?? ['in' => 0, 'out' => 0])
                <div class="erp-dl erp-dl-tight erp-dl-striped">
                    <dt>Paid out today</dt>
                    <dd class="erp-money-out">{{ number_format($today['out'], 2) }}</dd>
                    <dt>Received today</dt>
                    <dd class="erp-money-in">{{ number_format($today['in'], 2) }}</dd>
                    <dt>Net today</dt>
                    <dd class="erp-money-flat">{{ number_format($today['in'] - $today['out'], 2) }}</dd>
                </div>
            </div>
        </aside>
    </div>

    <x-ui.table-shell :title="'The payment book'" :count="$payments->count().' shown'">
        <thead>
            <tr>
                <th>Voucher</th>
                <th>Date</th>
                <th>From</th>
                <th>Paid to</th>
                <th class="erp-th-num">Amount</th>
                <th>Entry</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($payments as $payment)
                <tr>
                    <td data-label="Voucher"><span class="erp-cell-strong font-monospace">{{ $payment->receipt_no }}</span></td>
                    <td data-label="Date" class="erp-td-muted">{{ $payment->paid_at?->format('d M Y') }}</td>
                    <td data-label="From">
                        {{ $payment->account?->name ?? '—' }}
                        <span class="d-block erp-td-muted">{{ ucfirst($payment->method) }}</span>
                    </td>
                    <td data-label="Paid to">
                        {{ $payment->supplier?->name ?? $payment->narration ?? '—' }}
                        @if ($payment->reference)
                            <span class="d-block erp-td-muted font-monospace">{{ $payment->reference }}</span>
                        @endif
                    </td>
                    <td data-label="Amount" class="erp-td-num">
                        <span class="erp-money-out">{{ number_format((float) $payment->amount, 2) }}</span>
                    </td>
                    <td data-label="Entry" class="erp-td-muted font-monospace">
                        @if ($payment->journalEntry && $perm('accounting.journals.view'))
                            <a href="{{ route('accounting.journals.show', ['journal' => $payment->journalEntry->id]) }}">
                                {{ $payment->journalEntry->entry_no }}
                            </a>
                        @else
                            {{ $payment->journalEntry?->entry_no ?? '—' }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty icon="bi-box-arrow-up" title="No payment recorded yet"
                                    text="Money that leaves without a voucher leaves without a name — every payment here names its account, its payee and its entry." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
