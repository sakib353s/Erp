@extends('layouts.app')

@section('page_title', 'POS Receipt')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">POS Receipt</h1>
            <p class="erp-page-sub">
                {{ $txn->invoice?->invoice_no ?? $txn->client_uuid ?? 'POS sale' }} ·
                {{ optional($txn->sold_at)->format('d M Y, H:i') }}
            </p>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
            <a class="btn btn-outline-primary" href="{{ route('pos.terminal') }}">Back to terminal</a>
        </div>
    </div>

    <div class="erp-card mx-auto" style="max-width: {{ $paperWidth }}mm; font-family: monospace;">
        <div class="text-center mb-2">
            <div class="fw-bold">{{ $txn->session?->company?->name ?? config('app.name') }}</div>
            @if ($txn->session?->branch?->name)
                <div class="small">{{ $txn->session->branch->name }}</div>
            @endif
            <div class="small">Receipt {{ $txn->invoice?->invoice_no ?? $txn->id }}</div>
        </div>

        <div class="border-top border-bottom py-1 small">
            <div class="d-flex justify-content-between"><span>Sold at</span><span>{{ optional($txn->sold_at)->format('Y-m-d H:i') }}</span></div>
            <div class="d-flex justify-content-between"><span>Payment</span><span>{{ ucfirst($txn->payment_method) }}</span></div>
            <div class="d-flex justify-content-between"><span>Tendered</span><span>{{ number_format((float) $txn->tendered, 2) }}</span></div>
            <div class="d-flex justify-content-between"><span>Change</span><span>{{ number_format((float) $txn->change_due, 2) }}</span></div>
        </div>

        <div class="my-2">
            @forelse ($lines as $line)
                <div class="d-flex justify-content-between">
                    <span>{{ $line->description ?? $line->product?->name ?? 'Item' }} × {{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</span>
                    <span>{{ number_format((float) $line->line_total, 2) }}</span>
                </div>
            @empty
                <div class="text-muted small">No line detail recorded for this transaction.</div>
            @endforelse
        </div>

        <div class="border-top pt-1">
            @if ($invoice)
                <div class="d-flex justify-content-between"><span>Subtotal</span><span>{{ number_format((float) $invoice->subtotal, 2) }}</span></div>
                @if ((float) $invoice->tax > 0)
                    <div class="d-flex justify-content-between"><span>Tax</span><span>{{ number_format((float) $invoice->tax, 2) }}</span></div>
                @endif
                @if ((float) $invoice->rounding != 0.0)
                    <div class="d-flex justify-content-between"><span>Rounding</span><span>{{ number_format((float) $invoice->rounding, 2) }}</span></div>
                @endif
            @endif
            <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span>{{ number_format((float) $txn->total, 2) }}</span></div>
        </div>

        @if ($footer !== '')
            <div class="border-top mt-2 pt-2 text-center small">{{ $footer }}</div>
        @endif
    </div>
@endsection
