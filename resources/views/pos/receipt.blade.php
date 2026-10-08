@extends('layouts.app')

@section('page_title', 'POS Receipt')

@section('content')
    @php
        // §15-07: the slip obeys the same localization switches as the invoices.
        $loc = $localization ?? app(\App\Domain\Settings\Services\LocalizationService::class);
    @endphp
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
            <div class="d-flex justify-content-between"><span>Tendered</span><span>{{ $loc->number((float) $txn->tendered) }}</span></div>
            <div class="d-flex justify-content-between"><span>Change</span><span>{{ $loc->number((float) $txn->change_due) }}</span></div>
        </div>

        <div class="my-2">
            @forelse ($lines as $line)
                <div class="d-flex justify-content-between">
                    <span>{{ $line->description ?? $line->product?->name ?? 'Item' }} × {{ $loc->qty((float) $line->qty) }}</span>
                    <span>{{ $loc->number((float) $line->line_total) }}</span>
                </div>
            @empty
                <div class="text-muted small">No line detail recorded for this transaction.</div>
            @endforelse
        </div>

        <div class="border-top pt-1">
            @if ($invoice)
                @if ($taxInclusive ?? false)
                    <div class="d-flex justify-content-between"><span>Value (excl. VAT)</span><span>{{ $loc->number((float) $invoice->taxable_base) }}</span></div>
                    @if ((float) $invoice->tax > 0)
                        <div class="d-flex justify-content-between"><span>VAT (included)</span><span>{{ $loc->number((float) $invoice->tax) }}</span></div>
                    @endif
                @else
                    <div class="d-flex justify-content-between"><span>Subtotal</span><span>{{ $loc->number((float) $invoice->subtotal) }}</span></div>
                    @if ((float) $invoice->tax > 0)
                        <div class="d-flex justify-content-between"><span>Tax</span><span>{{ $loc->number((float) $invoice->tax) }}</span></div>
                    @endif
                @endif
                @if ((float) $invoice->rounding != 0.0)
                    <div class="d-flex justify-content-between"><span>Rounding</span><span>{{ $loc->number((float) $invoice->rounding) }}</span></div>
                @endif
            @endif
            <div class="d-flex justify-content-between fw-bold fs-5"><span>Total</span><span>{{ $loc->number((float) $txn->total) }}</span></div>
            @if ($loc->amountWordsEnabled())
                <div class="small text-center">{{ $loc->words((float) $txn->total) }}</div>
            @endif
        </div>

        @if ($footer !== '')
            <div class="border-top mt-2 pt-2 text-center small">{{ $footer }}</div>
        @endif
    </div>
@endsection
