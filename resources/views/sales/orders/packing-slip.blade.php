<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>PACKING SLIP {{ $order->order_no }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 13px; color: #111; margin: 2rem; }
        h1 { font-size: 20px; margin: 0 0 .25rem; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
        th, td { border: 1px solid #999; padding: .4rem .5rem; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
    </style>
</head>
<body>
@php
    $company = $order->company;
@endphp
<header>
    <h1>PACKING SLIP {{ $order->order_no }}</h1>
    <div class="muted">
        {{ $company?->legal_name ?? $company?->name ?? '' }}
        @if ($company?->address_line1)
            · {{ $company->address_line1 }}
        @endif
        <br>
        Order date: {{ optional($order->order_date)->toDateString() }}
        · Customer: {{ $order->customer?->name ?? 'Walk-in' }}
        @if ($order->customer?->code)
            ({{ $order->customer->code }})
        @endif
        @if ($order->warehouse?->name)
            · Ship from: {{ $order->warehouse->name }}
        @endif
        <br>
        Status: {{ $order->status }}
    </div>
</header>

<table>
    <thead>
    <tr>
        <th>#</th>
        <th>Item</th>
        <th class="num">Qty to pack</th>
    </tr>
    </thead>
    <tbody>
    @foreach ($order->lines as $line)
        <tr>
            <td>{{ $line->line_no }}</td>
            <td>{{ $line->product?->name ?? $line->description ?? '—' }}</td>
            <td class="num">{{ $line->qty }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
</body>
</html>
