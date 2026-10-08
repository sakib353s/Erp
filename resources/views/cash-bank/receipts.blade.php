@extends('layouts.app')

@section('page_title', 'Cash receipts')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Cash receipts"
        title="Money in"
        subtitle="One movement, one entry: the account the money landed in, the account it is against, and the date the bank or the customer says it moved. The voucher number comes from the money-receipt series, so a receipt book can be checked against the desk page by page."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
            </a>
            @if ($perm('cash.payments.create'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.payments') }}">
                    <i class="bi bi-box-arrow-up" aria-hidden="true"></i> Money out
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
                No money account is declared, so there is nowhere for money to land.
                @if ($perm('bank.accounts'))
                    <a class="fw-semibold" href="{{ route('cash-bank.accounts') }}">Declare one first</a>.
                @endif
            </div>
        </div>
    @else
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Record a receipt</h2>
                <div class="erp-card-actions">
                    <span class="erp-chip erp-chip-outline">{{ $moneyAccounts->count() }} account(s) money can land in</span>
                </div>
            </header>

            @include('cash-bank.partials.movement-form', [
                'direction' => 'in',
                'moneyAccounts' => $moneyAccounts,
                'counterAccounts' => $counterAccounts,
                'customers' => $customers,
                'suppliers' => collect(),
            ])
        </section>
    @endif

    <div class="erp-split mt-3">
        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Money received, last 14 days</h2>
            </header>
            <div class="erp-table-scroll">
                <table class="erp-table erp-table-compact">
                    <thead>
                        <tr><th>Day</th><th class="erp-th-num">Received</th></tr>
                    </thead>
                    <tbody>
                        @foreach (array_reverse($window, true) as $day => $net)
                            <tr>
                                <td data-label="Day" class="erp-td-muted">{{ \Illuminate\Support\Carbon::parse($day)->format('d M') }}</td>
                                <td data-label="Received" class="erp-td-num">
                                    <span class="erp-money-in">{{ $net['in'] > 0 ? number_format($net['in'], 2) : '—' }}</span>
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
                    <dt>Received today</dt>
                    <dd class="erp-money-in">{{ number_format($today['in'], 2) }}</dd>
                    <dt>Paid out today</dt>
                    <dd class="erp-money-out">{{ number_format($today['out'], 2) }}</dd>
                    <dt>Net today</dt>
                    <dd class="erp-money-flat">{{ number_format($today['in'] - $today['out'], 2) }}</dd>
                </div>
            </div>
        </aside>
    </div>

    <x-ui.table-shell :title="'The receipt book'" :count="$receipts->count().' shown'">
        <thead>
            <tr>
                <th>Receipt</th>
                <th>Date</th>
                <th>Into</th>
                <th>From</th>
                <th class="erp-th-num">Amount</th>
                <th>Entry</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($receipts as $receipt)
                <tr>
                    <td data-label="Receipt"><span class="erp-cell-strong font-monospace">{{ $receipt->receipt_no }}</span></td>
                    <td data-label="Date" class="erp-td-muted">{{ $receipt->paid_at?->format('d M Y') }}</td>
                    <td data-label="Into">
                        {{ $receipt->account?->name ?? '—' }}
                        <span class="d-block erp-td-muted">{{ ucfirst($receipt->method) }}</span>
                    </td>
                    <td data-label="From">
                        {{ $receipt->customer?->name ?? $receipt->narration ?? '—' }}
                        @if ($receipt->reference)
                            <span class="d-block erp-td-muted font-monospace">{{ $receipt->reference }}</span>
                        @endif
                    </td>
                    <td data-label="Amount" class="erp-td-num">
                        <span class="erp-money-in">{{ number_format((float) $receipt->amount, 2) }}</span>
                    </td>
                    <td data-label="Entry" class="erp-td-muted font-monospace">
                        @if ($receipt->journalEntry && $perm('accounting.journals.view'))
                            <a href="{{ route('accounting.journals.show', ['journal' => $receipt->journalEntry->id]) }}">
                                {{ $receipt->journalEntry->entry_no }}
                            </a>
                        @else
                            {{ $receipt->journalEntry?->entry_no ?? '—' }}
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6">
                        <x-ui.empty icon="bi-box-arrow-in-down" title="No receipt recorded yet"
                                    text="Every receipt written here becomes a money-receipt voucher and a balanced journal entry — nothing is filed from a screen alone." />
                    </td>
                </tr>
            @endforelse
        </tbody>
    </x-ui.table-shell>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
