@extends('layouts.app')

@section('page_title', 'Ledger — '.$product->sku)

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Product Stock Ledger</h1>
            <p class="erp-page-sub">
                <code>{{ $product->sku }}</code> · {{ $product->name }} ·
                <span class="erp-chip erp-chip-soft">{{ $product->cost_method }}</span>
                · On hand: <strong>{{ $ledger['on_hand'] }}</strong>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" href="{{ route('inventory.products.index') }}">Back to products</a>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.movements', ['product_id' => $product->id]) }}">All movements</a>
        </div>
    </div>

    <div class="erp-card">
        <div class="table-responsive">
            <table class="table erp-table align-middle mb-0">
                <thead>
                    <tr>
                        <th>Occurred</th>
                        <th>Type</th>
                        <th>State</th>
                        <th>Warehouse</th>
                        <th class="text-end">Qty</th>
                        <th class="text-end">Unit cost</th>
                        <th class="text-end">Running on-hand</th>
                        <th>Narration</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($ledger['rows'] as $row)
                        @php($movement = $row['movement'])
                        <tr>
                            <td class="small">{{ $movement->occurred_at?->format('d M Y H:i') }}</td>
                            <td><span class="erp-chip erp-chip-soft">{{ $movement->movement_type }}</span></td>
                            <td class="small">{{ $movement->state }}</td>
                            <td class="small">{{ $movement->warehouse?->name }}</td>
                            <td class="text-end">{{ number_format((float) $movement->qty_signed, 2) }}</td>
                            <td class="text-end">{{ number_format((float) $movement->unit_cost, 4) }}</td>
                            <td class="text-end fw-semibold">{{ number_format((float) $row['running_on_hand'], 2) }}</td>
                            <td class="small">{{ \Illuminate\Support\Str::limit($movement->narration ?? '', 40) }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="text-center py-4 text-body-secondary">No movements for this product.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">
        <x-ui.related-pages />
    </div>

@endsection
