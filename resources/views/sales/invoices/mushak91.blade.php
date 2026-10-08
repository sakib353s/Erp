<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $type->printed_title }} {{ $invoice->invoice_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 2rem; }
        h1 { font-size: 20px; margin: 0 0 .25rem; }
        .muted { color: #555; }
        .statutory-banner { border: 2px solid #111; padding: .6rem .8rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: baseline; }
        .statutory-banner .form-code { font-weight: bold; font-size: 15px; }
        .parties { display: flex; gap: 1rem; margin-bottom: 1rem; }
        .party { flex: 1; border: 1px solid #999; padding: .5rem .7rem; }
        .party h2 { font-size: 13px; margin: 0 0 .3rem; text-transform: uppercase; color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: .5rem; }
        th, td { border: 1px solid #999; padding: .4rem .5rem; text-align: left; }
        th:last-child, td:last-child, .num { text-align: right; }
        .totals { width: 20rem; margin-left: auto; margin-top: 1rem; }
        .totals div { display: flex; justify-content: space-between; padding: .2rem .4rem; }
        .totals .grand { font-weight: bold; border-top: 2px solid #111; }
        .note { margin-top: 1rem; border-left: 3px solid #999; padding: .3rem .6rem; color: #444; }
    </style>
</head>
<body>
@php
    // §15-07: the document's own localization — numerals, lakh/crore grouping and
    // whether the amount is spelled out. Renderable on its own, so the switches
    // are resolved from the container when the caller did not pass them.
    $loc = $localization ?? app(\App\Domain\Settings\Services\LocalizationService::class);

    $company = $invoice->company;
    $taxableSum = $invoice->lines->sum(fn ($line) => max(0, (float) $line->line_total - (float) $line->tax));
    $ratePercent = $rate !== null
        ? rtrim(rtrim(number_format((float) $rate->rate, 4, '.', ''), '0'), '.')
        : null;
@endphp

<div class="statutory-banner">
    <div>
        <div class="form-code">{{ $type->printed_title }}</div>
        <div class="muted">{{ $type->name }} · statutory document — separate from the commercial invoice print</div>
    </div>
    <div class="muted text-end">
        {{ $invoice->invoice_no }}<br>
        Date: {{ optional($invoice->invoice_date)->toDateString() }}
    </div>
</div>

<div class="parties">
    <div class="party">
        <h2>Seller</h2>
        <strong>{{ $company?->legal_name ?? $company?->name ?? '—' }}</strong>
        @if ($company?->address_line1)
            <br>{{ $company->address_line1 }}
        @endif
    </div>
    <div class="party">
        <h2>Buyer</h2>
        <strong>{{ $invoice->customer?->name ?? 'Walk-in' }}</strong>
        @if ($invoice->customer?->code)
            <br>Code: {{ $invoice->customer->code }}
        @endif
        @if ($invoice->customer?->email)
            <br>{{ $invoice->customer->email }}
        @endif
    </div>
</div>

@if ($rate !== null)
    <div class="note">
        Effective tax rate for code <strong>{{ $invoice->tax_code }}</strong> on
        {{ optional($invoice->invoice_date)->toDateString() }}:
        {{ $rate->name }} ({{ $rate->tax_type }}) — {{ $ratePercent }}%
        @if ($rate->effective_from)
            · effective from {{ $rate->effective_from->toDateString() }}
        @endif
        @if ($rate->effective_to)
            · until {{ $rate->effective_to->toDateString() }}
        @endif
    </div>
@else
    <div class="note">
        No effective tax rate is configured for code
        <strong>{{ $invoice->tax_code ?? '—' }}</strong> on
        {{ optional($invoice->invoice_date)->toDateString() }} — line rates below show “—”
        while the VAT amounts remain the invoice's own recorded tax.
    </div>
@endif

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Description</th>
        <th class="num">Qty</th>
        <th class="num">Unit price</th>
        <th class="num">Taxable value</th>
        <th class="num">Rate %</th>
        <th class="num">VAT</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($invoice->lines as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td>{{ $line->product?->name ?? $line->description ?? '—' }}</td>
            <td class="num">{{ $line->qty }}</td>
            <td class="num">{{ $loc->number((float) $line->unit_price) }}</td>
            <td class="num">{{ $loc->number(max(0, (float) $line->line_total - (float) $line->tax)) }}</td>
            <td class="num">{{ $ratePercent ?? '—' }}</td>
            <td class="num">{{ $loc->number((float) $line->tax) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<div class="totals">
    <div><span>Taxable value</span><span>{{ $loc->number((float) ($invoice->taxable_base ?: $taxableSum)) }}</span></div>
    <div><span>VAT</span><span>{{ $loc->number((float) $invoice->tax) }}</span></div>
    @if ((float) $invoice->shipping > 0)
        <div><span>Shipping</span><span>{{ $loc->number((float) $invoice->shipping) }}</span></div>
    @endif
    <div class="grand"><span>Grand total</span><span>{{ $loc->number((float) $invoice->grand_total) }}</span></div>
    @if ($loc->amountWordsEnabled())
        <div class="muted" style="display:block;padding:.35rem .4rem;">
            In words: {{ $loc->words((float) $invoice->grand_total) }}
            @if (($loc->locale() === 'bn'))
                <br>{{ $loc->words((float) $invoice->grand_total, 'bn') }}
            @endif
        </div>
    @endif
</div>
</body>
</html>
