<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->printed_title ?? 'INVOICE' }} {{ $invoice->invoice_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 2rem; }
        h1 { font-size: 20px; margin: 0 0 .25rem; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #999; padding: .4rem .5rem; text-align: left; }
        th:last-child, td:last-child, .num { text-align: right; }
        .totals { width: 18rem; margin-left: auto; margin-top: 1rem; }
        .totals div { display: flex; justify-content: space-between; padding: .2rem .4rem; }
        .totals .grand { font-weight: bold; border-top: 2px solid #111; }
    </style>
</head>
<body>
@php
    $company = $invoice->company;
    $showTax = (float) $invoice->tax > 0
        || $invoice->lines->contains(fn ($line) => (float) $line->tax > 0);
@endphp
<header>
    <h1>{{ $invoice->printed_title ?? 'INVOICE' }} {{ $invoice->invoice_no }}</h1>
    <div class="muted">
        {{ $company?->legal_name ?? $company?->name ?? '' }}
        @if ($company?->address_line1)
            · {{ $company->address_line1 }}
        @endif
        <br>
        Date: {{ optional($invoice->invoice_date)->toDateString() }}
        · Customer: {{ $invoice->customer?->name ?? 'Walk-in' }}
        @if ($invoice->customer?->code)
            ({{ $invoice->customer->code }})
        @endif
    </div>
</header>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Item</th>
        <th class="num">Qty</th>
        <th class="num">Unit price</th>
        @if ($showTax)
            <th class="num">Tax</th>
        @endif
        <th class="num">Line total</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td>{{ $line->product?->name ?? $line->description ?? '—' }}</td>
            <td class="num">{{ $line->qty }}</td>
            <td class="num">{{ number_format((float) $line->unit_price, 2) }}</td>
            @if ($showTax)
                <td class="num">{{ number_format((float) $line->tax, 2) }}</td>
            @endif
            <td class="num">{{ number_format((float) $line->line_total, 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <div><span>Subtotal</span><span>{{ number_format((float) $invoice->subtotal, 2) }}</span></div>
    @if ($showTax)
        <div><span>Tax</span><span>{{ number_format((float) $invoice->tax, 2) }}</span></div>
    @endif
    @if ((float) $invoice->shipping > 0)
        <div><span>Shipping</span><span>{{ number_format((float) $invoice->shipping, 2) }}</span></div>
    @endif
    <div class="grand"><span>Grand total</span><span>{{ number_format((float) $invoice->grand_total, 2) }}</span></div>
</div>
</body>
</html>
