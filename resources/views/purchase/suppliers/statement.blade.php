@php($company = \App\Domain\Foundation\Company::current())
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Supplier statement · {{ $supplier->name }} · {{ $range['from'] }} → {{ $range['to'] }}</title>
    @vite(['resources/css/app.css'])
    <style>
        /* A document, not a screen: white paper, no shell, no navigation. The
           toolbar disappears when printing (§18.2 PrintableDocumentFrame). */
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
        <a class="btn btn-light" href="{{ route('suppliers.ledger', $supplier) }}">Back to ledger</a>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('suppliers.statement', array_filter(['from' => $range['from'], 'to' => $range['to'], 'format' => 'csv'])) }}">
                <i class="bi bi-filetype-csv" aria-hidden="true"></i> Download CSV
            </a>
            <button class="btn btn-primary" type="button" onclick="window.print()">
                <i class="bi bi-printer" aria-hidden="true"></i> Print statement
            </button>
        </div>
    </div>

    <header class="erp-doc-head">
        <div>
            <h1 class="erp-doc-title">{{ $company?->name ?? config('app.name') }}</h1>
            @if ($company?->address_line1)
                <p class="erp-doc-sub">{{ $company->address_line1 }}@if($company?->district), {{ $company->district->name ?? '' }}@endif</p>
            @endif
            @if ($company?->bin)<p class="erp-doc-sub">BIN: {{ $company->bin }}</p>@endif
        </div>
        <div class="text-end">
            <h2 class="erp-doc-kind">Supplier statement of account</h2>
            <p class="erp-doc-sub">{{ $range['from'] }} → {{ $range['to'] }}</p>
            <p class="erp-doc-sub">Generated {{ now()->format('d M Y, h:i A') }}</p>
        </div>
    </header>

    <section class="erp-doc-party">
        <div>
            <p class="erp-doc-label">Account of</p>
            <p class="erp-doc-strong">{{ $supplier->name }}</p>
            <p class="erp-doc-sub">
                {{ $supplier->code }}
                @if ($supplier->phone) · {{ $supplier->phone }}@endif
                @if ($supplier->bin) · BIN {{ $supplier->bin }}@endif
            </p>
            @if ($supplier->address_line1)
                <p class="erp-doc-sub">{{ $supplier->address_line1 }}@if ($supplier->district), {{ $supplier->district->name }}@endif</p>
            @endif
        </div>
        <div class="text-end">
            <p class="erp-doc-label">Closing balance owed</p>
            <p class="erp-doc-total">৳ {{ number_format($ledger['closing'], 2) }}</p>
            <p class="erp-doc-sub">{{ $ledger['closing'] > 0 ? 'Payable by us' : 'Nothing payable' }}</p>
        </div>
    </section>

    <table class="erp-doc-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Reference</th>
                <th>Particulars</th>
                <th class="erp-th-num">Settled (Dr)</th>
                <th class="erp-th-num">Billed (Cr)</th>
                <th class="erp-th-num">Balance owed</th>
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

    @if ($payables['due'] > 0)
        <section class="erp-doc-party mt-4">
            <div>
                <p class="erp-doc-label">Outstanding bills by age</p>
                <table class="erp-doc-table">
                    <thead>
                        <tr>
                            <th>Bucket</th>
                            <th class="erp-th-num">Bills</th>
                            <th class="erp-th-num">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach (['current' => 'Not yet due', 'd1_30' => '1–30 days late', 'd31_60' => '31–60 days late', 'd61_90' => '61–90 days late', 'd90_plus' => '90+ days late'] as $bucket => $label)
                            <tr>
                                <td>{{ $label }}</td>
                                <td class="erp-td-num">{{ $payables['buckets'][$bucket]['count'] ?? 0 }}</td>
                                <td class="erp-td-num">{{ number_format($payables['buckets'][$bucket]['amount'] ?? 0, 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="text-end">
                <p class="erp-doc-label">Total outstanding</p>
                <p class="erp-doc-total">৳ {{ number_format($payables['due'], 2) }}</p>
                @if ($payables['overdue'] > 0)
                    <p class="erp-doc-sub">of which past due ৳ {{ number_format($payables['overdue'], 2) }}</p>
                @endif
            </div>
        </section>
    @endif

    <footer class="erp-doc-foot">
        <p>
            This statement is generated from posted documents only: approved bills, recorded payments and posted
            purchase returns. Orders and goods receipts are not money and are not listed.
        </p>
        <p class="erp-doc-sub">{{ config('app.name') }} · {{ now()->format('d M Y') }}</p>
    </footer>
</div>
</body>
</html>
