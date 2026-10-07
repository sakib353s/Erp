<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>SHIPPING LABEL {{ $label->label_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 2rem; }
        h1 { font-size: 18px; margin: 0 0 .25rem; }
        .muted { color: #555; }
        .box { border: 2px solid #111; padding: .8rem; margin-top: 1rem; max-width: 26rem; }
        .to { font-size: 15px; font-weight: bold; }
        dl { margin: .6rem 0 0; }
        dt { color: #555; font-size: 11px; text-transform: uppercase; }
        dd { margin: 0 0 .5rem; font-size: 14px; }
        .tracking { font-family: monospace; font-size: 15px; }
    </style>
</head>
<body>
@php
    $company = $order->company;
@endphp
<h1>SHIPPING LABEL {{ $label->label_no }}</h1>
<div class="muted">
    {{ $company?->legal_name ?? $company?->name ?? '' }}
    @if ($company?->address_line1)
        · {{ $company->address_line1 }}
    @endif
    · Order {{ $order->order_no }}
</div>

<div class="box">
    <div class="muted">To</div>
    <div class="to">{{ $label->receiver_name }}</div>
    <div>{{ $label->receiver_address }}</div>
    <div>{{ $label->district }}</div>
    <div>{{ $label->receiver_phone }}</div>
</div>

<dl>
    @if ($label->courier)
        <dt>Courier</dt>
        <dd>{{ $label->courier->name }}@if($label->tracking_code) · <span class="tracking">{{ $label->tracking_code }}</span>@endif</dd>
    @else
        <dt>Courier</dt>
        <dd>Not assigned</dd>
    @endif
    @if ($label->parcel_description)
        <dt>Parcel</dt>
        <dd>{{ $label->parcel_description }}</dd>
    @endif
    @if ($label->weight_kg !== null)
        <dt>Weight</dt>
        <dd>{{ number_format((float) $label->weight_kg, 2) }} kg</dd>
    @endif
</dl>
</body>
</html>
