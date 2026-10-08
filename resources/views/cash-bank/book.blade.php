@extends('layouts.app')

@section('page_title', $account->name.' — book')

@section('content')
    <x-ui.page-header
        eyebrow="Cash & bank · Bank transactions"
        :title="$account->name"
        subtitle="The account's book, straight from the general ledger: every posted line with a running balance, opening brought forward and nothing rounded on the way. This is the page a bank statement is checked against, line by line — and the CSV beside it is the same rows, so a reconciliation can be done in a spreadsheet without retyping."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.index') }}">
                <i class="bi bi-wallet2" aria-hidden="true"></i> Where the money is
            </a>
            @if ($perm('bank.accounts'))
                <a class="btn btn-outline-secondary" href="{{ route('cash-bank.accounts.edit', ['account' => $account->id]) }}">
                    <i class="bi bi-pencil" aria-hidden="true"></i> This account
                </a>
            @endif
            <a class="btn btn-outline-secondary"
               href="{{ route('cash-bank.book', ['account' => $account->id, 'from' => $filters['from'], 'to' => $filters['to'], 'format' => 'csv']) }}">
                <i class="bi bi-download" aria-hidden="true"></i> CSV
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @unless ($account->is_active)
        <div class="erp-note erp-note-warn mb-3">
            <i class="bi bi-lock" aria-hidden="true"></i>
            <div>This account is closed to new movement. Its history is intact and stays in the books.</div>
        </div>
    @endunless

    <div class="erp-kpi-grid mb-3">
        <x-ui.kpi label="Opening, brought forward" :value="'৳ '.number_format((float) $opening, 2)" icon="bi-box-arrow-in-left"
                  hint="Everything posted before the window starts" />
        <x-ui.kpi label="Money in" :value="'৳ '.number_format((float) $debitTotal, 2)" icon="bi-arrow-down-circle"
                  hint="Debits to this account in the window" />
        <x-ui.kpi label="Money out" :value="'৳ '.number_format((float) $creditTotal, 2)" icon="bi-arrow-up-circle"
                  hint="Credits to this account in the window" />
        <x-ui.kpi label="Closing" :value="'৳ '.number_format((float) $closing, 2)" icon="bi-wallet2"
                  hint="What the books say is there today" />
    </div>

    <div class="erp-card erp-card-tight mb-3">
        <div class="erp-dl erp-dl-tight erp-dl-striped">
            <dt>Account</dt>
            <dd><span class="font-monospace">{{ $account->code }}</span> · {{ $label }}</dd>
            <dt>Held with</dt>
            <dd>
                {{ $account->bank_name ?: ($account->wallet_provider ?: 'the company itself (cash drawer)') }}
                @if ($account->account_number)
                    · <span class="font-monospace">{{ $account->account_number }}</span>
                @endif
            </dd>
            <dt>Currency</dt>
            <dd>{{ $account->currency }}</dd>
            <dt>Ledger lines</dt>
            <dd>{{ number_format($position['movements']) }}{{ $position['last_movement_on'] ? ', last on '.$position['last_movement_on'] : '' }}</dd>
        </div>
    </div>

    <form class="erp-filterbar" method="GET" action="{{ route('cash-bank.book', ['account' => $account->id]) }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $filters['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $filters['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
            <a class="btn btn-outline-secondary" href="{{ route('cash-bank.book', ['account' => $account->id]) }}">Clear</a>
        </div>
        <div class="erp-filter-note">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            Narrowing the window moves the opening line with it: the balance at the top is everything posted before the
            start date, not a guess.
        </div>
    </form>

    <x-ui.table-shell :title="'The book'" :count="count($rows).' line(s), opening included'">
        <thead>
            <tr>
                <th>Date</th>
                <th>Entry</th>
                <th>What it was</th>
                <th class="erp-th-num">In</th>
                <th class="erp-th-num">Out</th>
                <th class="erp-th-num">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    <td data-label="Date" class="erp-td-muted">{{ $row['entry_date'] ?? '—' }}</td>
                    <td data-label="Entry" class="font-monospace">
                        @if (($row['journal_entry_id'] ?? null) && $perm('accounting.journals.view'))
                            <a href="{{ route('accounting.journals.show', ['journal' => $row['journal_entry_id']]) }}">
                                {{ $row['entry_no'] ?? 'Opening' }}
                            </a>
                        @else
                            {{ $row['entry_no'] ?? 'Opening' }}
                        @endif
                    </td>
                    <td data-label="What it was">
                        {{ $row['description'] }}
                        @if (($row['is_opening'] ?? false))
                            <span class="d-block erp-td-muted">Everything posted before {{ $filters['from'] ?? 'the books opened' }}</span>
                        @endif
                    </td>
                    <td data-label="In" class="erp-td-num">
                        @if (($row['debit'] ?? '0.0000') + 0 > 0)
                            <span class="erp-money-in">{{ number_format((float) $row['debit'], 2) }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Out" class="erp-td-num">
                        @if (($row['credit'] ?? '0.0000') + 0 > 0)
                            <span class="erp-money-out">{{ number_format((float) $row['credit'], 2) }}</span>
                        @else
                            <span class="erp-td-muted">—</span>
                        @endif
                    </td>
                    <td data-label="Balance" class="erp-td-num">
                        <span class="erp-cell-strong @if (($row['running_balance'] ?? '0') + 0 < 0) erp-money-out @endif">
                            {{ number_format((float) $row['running_balance'], 2) }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </x-ui.table-shell>

    <div class="erp-note erp-note-info mt-3">
        <i class="bi bi-bank" aria-hidden="true"></i>
        <div>
            <strong class="d-block mb-1">Matching this against a statement</strong>
            Tick the lines the bank also shows. What is left on this page and not on the statement is money recorded
            here that has not cleared yet — a cheque in the post, a deposit on its way. What is on the statement and
            not here is a movement nobody has entered: that is the number to hunt, because the books are only as good
            as the movements they were told about.
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>
@endsection
