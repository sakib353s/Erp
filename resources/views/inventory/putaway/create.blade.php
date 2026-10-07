@extends('layouts.app')

@section('page_title', 'New putaway list')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="New putaway list"
        subtitle="Putting away starts from a receipt: the receipt is what brought the stock in. Leave it empty to instruct a move by hand."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.putaway-lists.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All putaway lists
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('putaway_list') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <form method="POST" action="{{ route('inventory.putaway-lists.store') }}">
        @csrf

        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Which delivery, and where</h2>
            </header>
            <div class="row g-3">
                <div class="col-md-5">
                    <label class="form-label" for="goods_receipt_id">Receipt</label>
                    <select class="form-select" id="goods_receipt_id" name="goods_receipt_id">
                        <option value="">No receipt — a manual move</option>
                        @foreach ($receipts as $receipt)
                            <option value="{{ $receipt->id }}" @selected((int) old('goods_receipt_id', $selectedReceipt?->id) === (int) $receipt->id)>
                                {{ $receipt->code }} · {{ $receipt->supplier?->name ?? 'supplier unknown' }}
                                · received {{ $receipt->received_date?->format('d M Y') }}
                            </option>
                        @endforeach
                    </select>
                    @error('goods_receipt_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    <div class="form-text">
                        Posted receipts nobody has put away yet. A receipt already covered by a live list is not listed —
                        finish or cancel that one first.
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id">
                        <option value="">Taken from the receipt</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}" @selected((int) old('warehouse_id') === (int) $warehouse->id)>
                                {{ $warehouse->code }} · {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    <div class="form-text">Only needed for a manual move.</div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}"
                           placeholder="Who is unloading, and where">
                    @error('notes') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>

        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Lines, if there is no receipt</h2>
                <span class="erp-chip erp-chip-outline">{{ $blankRows }} blank row(s)</span>
            </header>
            <p class="erp-td-muted small mb-2">
                Fill as many rows as the move needs; blank rows are ignored. The same product twice is added up.
            </p>
            <div class="table-responsive">
                <table class="table erp-table align-middle">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th class="erp-th-num">Quantity</th>
                        </tr>
                    </thead>
                    <tbody>
                        @for ($i = 0; $i < $blankRows; $i++)
                            <tr>
                                <td>
                                    <select class="form-select" name="lines[{{ $i }}][product_id]"
                                            aria-label="Product for row {{ $i + 1 }}">
                                        <option value="">— nothing —</option>
                                        @foreach ($products as $product)
                                            <option value="{{ $product->id }}"
                                                @selected((int) old("lines.$i.product_id") === (int) $product->id)>
                                                {{ $product->sku }} · {{ $product->name }}
                                            </option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input class="form-control text-end" type="number" step="0.0001" min="0"
                                           name="lines[{{ $i }}][quantity]" value="{{ old("lines.$i.quantity") }}"
                                           aria-label="Quantity for row {{ $i + 1 }}">
                                </td>
                            </tr>
                        @endfor
                    </tbody>
                </table>
            </div>
        </section>

        <div class="d-flex gap-2 mt-3">
            <a class="btn btn-outline-secondary" href="{{ route('inventory.putaway-lists.index') }}">Cancel</a>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-box-arrow-in-down" aria-hidden="true"></i> Create the putaway list
            </button>
        </div>
    </form>
@endsection
