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
    // §15-07: the commercial print obeys the same switches as the statutory one.
    $loc = $localization ?? app(\App\Domain\Settings\Services\LocalizationService::class);
    // §15-14: when prices already include VAT, the document may not print a
    // subtotal that the tax is then added to — the customer would read two
    // different totals. It prints the taxable value, the VAT inside it, and the
    // figure actually being asked for.
    $taxInclusive = $taxInclusive ?? false;
@endphp
@php
    // §16-19/§16-20: the paper carries the verification link when one has been
    // published. A reprint recomputes the same address, so old and new copies of
    // the same invoice verify identically until somebody rotates or withdraws it.
    $verificationUrl = $verificationUrl ?? null;
    $verificationQr = $verificationQr ?? null;
@endphp
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
            <td class="num">{{ $loc->number((float) $line->unit_price) }}</td>
            @if ($showTax)
                <td class="num">{{ $loc->number((float) $line->tax) }}</td>
            @endif
            <td class="num">{{ $loc->number((float) $line->line_total) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    @if ($taxInclusive)
        <div><span>Taxable value</span><span>{{ $loc->number((float) $invoice->taxable_base) }}</span></div>
        @if ($showTax)
            <div><span>VAT (included in the prices)</span><span>{{ $loc->number((float) $invoice->tax) }}</span></div>
        @endif
    @else
        <div><span>Subtotal</span><span>{{ $loc->number((float) $invoice->subtotal) }}</span></div>
        @if ($showTax)
            <div><span>Tax</span><span>{{ $loc->number((float) $invoice->tax) }}</span></div>
        @endif
    @endif
    @if ((float) $invoice->shipping > 0)
        <div><span>Shipping</span><span>{{ $loc->number((float) $invoice->shipping) }}</span></div>
    @endif
    <div class="grand"><span>Grand total</span><span>{{ $loc->number((float) $invoice->grand_total) }}</span></div>
    @if ($loc->amountWordsEnabled())
        <div class="muted" style="display:block;padding:.2rem .4rem;">In words: {{ $loc->words((float) $invoice->grand_total) }}</div>
    @endif
</div>

@if ($verificationUrl)
    <div style="margin-top:1.25rem;display:flex;gap:.75rem;align-items:center;border-top:1px solid #ccc;padding-top:.75rem;">
        <div style="width:104px;flex:0 0 104px;">{!! $verificationQr !!}</div>
        <div class="muted" style="font-size:11px;">
            Scan to check this invoice on our books.<br>
            <span style="word-break:break-all;">{{ $verificationUrl }}</span>
        </div>
    </div>
@endif
</body>
</html>
