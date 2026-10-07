<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement · {{ $customer->name }} · {{ $range['from'] }} → {{ $range['to'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /* Statement is a document: white paper, no shell, no navigation (§18.2
           PrintableDocumentFrame). Printing hides the toolbar. */
        body { background: #fff; }
        .erp-doc { max-width: 210mm; margin: 0 auto; padding: 24px 20px 48px; }
        @media print {
            .erp-no-print { display: none !important; }
            .erp-doc { padding: 0; max-width: none; }
        }
    </style>
</head>
<body class="erp-body">
<div class="erp-doc">
    <div class="erp-no-print d-flex justify-content-between align-items-center mb-3">
        <a class="btn btn-light" href="{{ route('customers.show', $customer) }}">Back to profile</a>
        <button class="btn btn-primary" type="button" onclick="window.print()">
            <i class="bi bi-printer" aria-hidden="true"></i> Print statement
        </button>
    </div>

    @php($company = \App\Domain\Foundation\Company::current())

    <header class="erp-doc-head">
        <div>
            <h1 class="erp-doc-title">{{ $company?->name ?? config('app.name') }}</h1>
            @if ($company?->address_line1)
                <p class="erp-doc-sub">{{ $company->address_line1 }}@if($company->district) , {{ $company->district->name ?? '' }}@endif</p>
            @endif
            @if ($company?->bin)<p class="erp-doc-sub">BIN: {{ $company->bin }}</p>@endif
        </div>
        <div class="text-end">
            <h2 class="erp-doc-kind">Customer statement</h2>
            <p class="erp-doc-sub">{{ $range['from'] }} → {{ $range['to'] }}</p>
            <p class="erp-doc-sub">Generated {{ now()->format('d M Y, h:i A') }}</p>
        </div>
    </header>

    <section class="erp-doc-party">
        <div>
            <p class="erp-doc-label">Statement for</p>
            <p class="erp-doc-strong">{{ $customer->name }}</p>
            <p class="erp-doc-sub">
                {{ $customer->code }}
                @if ($customer->phone) · {{ $customer->phone }}@endif
                @if ($customer->bin) · BIN {{ $customer->bin }}@endif
            </p>
            @if ($customer->defaultAddress())
                <p class="erp-doc-sub">{{ $customer->defaultAddress()->oneLine() }}</p>
            @endif
        </div>
        <div class="text-end">
            <p class="erp-doc-label">Closing balance</p>
            <p class="erp-doc-total">৳ {{ number_format($ledger['closing'], 2) }}</p>
            <p class="erp-doc-sub">{{ $ledger['closing'] > 0 ? 'Payable to us' : 'Settled / advance held' }}</p>
        </div>
    </section>

    <table class="erp-doc-table">
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
            <tr>
                <td>{{ $range['from'] }}</td>
                <td>—</td>
                <td><strong>Opening balance</strong></td>
                <td class="erp-td-num">—</td>
                <td class="erp-td-num">—</td>
                <td class="erp-td-num erp-amount">{{ number_format($ledger['opening'], 2) }}</td>
            </tr>
            @foreach ($ledger['lines'] as $line)
                <tr>
                    <td>{{ $line['date'] }}</td>
                    <td>{{ $line['reference'] }}</td>
                    <td>{{ $line['description'] }}</td>
                    <td class="erp-td-num">{{ $line['debit'] > 0 ? number_format($line['debit'], 2) : '—' }}</td>
                    <td class="erp-td-num">{{ $line['credit'] > 0 ? number_format($line['credit'], 2) : '—' }}</td>
                    <td class="erp-td-num erp-amount">{{ number_format($line['balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <th colspan="3">Period totals</th>
                <th class="erp-td-num">{{ number_format($ledger['totals']['debit'], 2) }}</th>
                <th class="erp-td-num">{{ number_format($ledger['totals']['credit'], 2) }}</th>
                <th class="erp-td-num">{{ number_format($ledger['closing'], 2) }}</th>
            </tr>
        </tfoot>
    </table>

    <footer class="erp-doc-foot">
        <p>
            This statement is generated from posted accounting entries; it lists every invoice and receipt
            in the period. Please report any discrepancy within 7 days of the statement date.
        </p>
        <p class="erp-doc-sub">{{ config('app.name') }} · {{ now()->format('d M Y') }}</p>
    </footer>
</div>
</body>
</html>
