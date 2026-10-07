@extends('layouts.guest')

@section('page_title', 'Quotation '.$quotation->quote_no)

@section('content')
    <div class="mb-3">
        <h1 class="h4 mb-1">Quotation <code>{{ $quotation->quote_no }}</code></h1>
        <p class="text-muted mb-0">
            Revision {{ $quotation->revision }}
            · {{ optional($quotation->quote_date)->toDateString() }}
            @if ($quotation->valid_until)
                · valid until {{ $quotation->valid_until->toDateString() }}
            @endif
            · status {{ $quotation->status }}
        </p>
    </div>

    <div class="mb-3">
        <strong>{{ $quotation->customer?->name ?? 'Valued customer' }}</strong>
        @if ($quotation->customer?->email)
            <div class="text-muted small">{{ $quotation->customer->email }}</div>
        @endif
    </div>

    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="text-end">Qty</th>
                    <th class="text-end">Unit price</th>
                    <th class="text-end">Discount</th>
                    <th class="text-end">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($quotation->lines as $line)
                    <tr>
                        <td>
                            <div>{{ $line->product?->name ?? $line->description }}</div>
                            <div class="small text-muted">{{ $line->product?->sku }}</div>
                        </td>
                        <td class="text-end">{{ rtrim(rtrim(number_format((float) $line->qty, 4), '0'), '.') }}</td>
                        <td class="text-end">{{ number_format((float) $line->unit_price, 2) }}</td>
                        <td class="text-end">{{ number_format((float) $line->discount, 2) }}</td>
                        <td class="text-end">{{ number_format((float) $line->line_total, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th colspan="4" class="text-end">Subtotal</th>
                    <td class="text-end">{{ number_format((float) $quotation->subtotal, 2) }}</td>
                </tr>
                <tr>
                    <th colspan="4" class="text-end">Discount</th>
                    <td class="text-end">{{ number_format((float) $quotation->discount, 2) }}</td>
                </tr>
                <tr>
                    <th colspan="4" class="text-end">Tax</th>
                    <td class="text-end">{{ number_format((float) $quotation->tax, 2) }}</td>
                </tr>
                <tr>
                    <th colspan="4" class="text-end">Shipping</th>
                    <td class="text-end">{{ number_format((float) $quotation->shipping, 2) }}</td>
                </tr>
                <tr class="table-light">
                    <th colspan="4" class="text-end">Grand total ({{ $quotation->currency }})</th>
                    <td class="text-end fw-semibold">{{ number_format((float) $quotation->grand_total, 2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    @if ($quotation->notes)
        <p class="text-muted small mt-3 mb-0">{{ $quotation->notes }}</p>
    @endif

    <p class="text-muted small mt-3 mb-0">
        Read-only view — figures are server-computed. Contact your sales person to accept or revise.
    </p>
@endsection
