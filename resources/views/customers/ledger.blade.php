@extends('layouts.app')

@section('page_title', 'Ledger · '.$customer->name)

@section('content')
    <x-ui.page-header
        eyebrow="Customer ledger · {{ $customer->code }}"
        :title="$customer->name"
        subtitle="Every movement that touched this customer's account: invoices debit, receipts credit. The closing balance is the same figure the trial balance reports for this control account.">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('customers.statement', array_filter(['from' => $range['from'], 'to' => $range['to']])) }}" target="_blank" rel="noopener">
                <i class="bi bi-printer" aria-hidden="true"></i> Statement
            </a>
            <a class="btn btn-light" href="{{ route('customers.show', $customer) }}">Profile</a>
        </x-slot:actions>
    </x-ui.page-header>

    <form class="erp-filterbar" method="GET" action="{{ route('customers.ledger', $customer) }}" role="search">
        <div class="erp-filter">
            <label class="form-label" for="from">From</label>
            <input class="form-control" type="date" id="from" name="from" value="{{ $range['from'] }}">
        </div>
        <div class="erp-filter">
            <label class="form-label" for="to">To</label>
            <input class="form-control" type="date" id="to" name="to" value="{{ $range['to'] }}">
        </div>
        <div class="erp-filterbar-actions">
            @if ($range['from'] || $range['to'])
                <a class="btn btn-link" href="{{ route('customers.ledger', $customer) }}">Whole history</a>
            @endif
            <button class="btn btn-primary" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply</button>
        </div>
    </form>

    <x-ui.table-shell title="Ledger" :count="count($ledger['lines']).' lines'">
        <x-slot:tools>
            <span class="erp-chip erp-chip-outline">
                Opening {{ number_format($ledger['opening'], 2) }}
            </span>
            <span class="erp-chip {{ $ledger['closing'] > 0 ? 'erp-chip-warn' : 'erp-chip-ok' }}">
                Closing {{ number_format($ledger['closing'], 2) }}
            </span>
        </x-slot:tools>

        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Particulars</th>
                <th class="erp-th-num">Debit</th>
                <th class="erp-th-num">Credit</th>
                <th class="erp-th-num">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr class="erp-table-opening">
                <td data-label="Date" class="erp-td-muted">{{ $range['from'] ?? 'Opening' }}</td>
                <td data-label="Reference">—</td>
                <td data-label="Particulars"><strong>Opening balance</strong></td>
                <td colspan="3" data-label="Balance" class="erp-td-num erp-amount">{{ number_format($ledger['opening'], 2) }}</td>
            </tr>

            @forelse ($ledger['lines'] as $line)
                <tr>
                    <td data-label="Date" class="erp-td-muted">{{ $line['date'] }}</td>
                    <td data-label="Reference">{{ $line['reference'] }}</td>
                    <td data-label="Particulars">{{ $line['description'] }}</td>
                    <td data-label="Debit" class="erp-td-num">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '—' }}</td>
                    <td data-label="Credit" class="erp-td-num">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '—' }}</td>
                    <td data-label="Balance" class="erp-td-num erp-amount">{{ number_format($line['balance'], 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="p-0">
                        <x-ui.empty
                            icon="bi-journal-x"
                            title="No movement in this period"
                            text="Widen the date range, or clear it to see the whole account history." />
                    </td>
                </tr>
            @endforelse
        </tbody>

        <x-slot:footer>
            <span>Debits {{ number_format($ledger['totals']['debit'], 2) }} · Credits {{ number_format($ledger['totals']['credit'], 2) }}</span>
            <span class="erp-amount">Closing {{ number_format($ledger['closing'], 2) }}</span>
        </x-slot:footer>
    </x-ui.table-shell>

    <div class="erp-note mt-3">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <div>
            Dues are derived, never stored: this ledger reads the same invoice and receipt rows the
            accounting engine posts, so it cannot drift from the books.
        </div>
    </div>
@endsection
