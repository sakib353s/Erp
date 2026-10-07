@extends('layouts.app')

@section('page_title', 'New pick list')

@section('content')
    <x-ui.page-header
        eyebrow="Inventory · Warehouse"
        title="New pick list"
        subtitle="Pick an order and the list is written from what it still owes the customer — or leave the order empty and write the lines yourself."
        :pin="true">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('inventory.pick-lists.index') }}">
                <i class="bi bi-arrow-left" aria-hidden="true"></i> All pick lists
            </a>
        </x-slot:actions>
    </x-ui.page-header>

    @error('pick_list') <div class="alert alert-danger">{{ $message }}</div> @enderror
    @error('lines') <div class="alert alert-danger">{{ $message }}</div> @enderror

    <form method="POST" action="{{ route('inventory.pick-lists.store') }}">
        @csrf

        <section class="erp-card mb-3">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Where and for whom</h2>
            </header>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="warehouse_id">Warehouse</label>
                    <select class="form-select" id="warehouse_id" name="warehouse_id" required>
                        <option value="">Pick a warehouse</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}"
                                @selected((int) old('warehouse_id', $selectedOrder?->warehouse_id) === (int) $warehouse->id)>
                                {{ $warehouse->code }} · {{ $warehouse->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label" for="sales_order_id">Order (optional)</label>
                    <select class="form-select" id="sales_order_id" name="sales_order_id">
                        <option value="">No order — I will write the lines below</option>
                        @foreach ($orders as $order)
                            <option value="{{ $order->id }}" @selected((int) old('sales_order_id', $selectedOrder?->id) === (int) $order->id)>
                                {{ $order->order_no }} · {{ $order->customer?->name ?? 'walk-in customer' }} · {{ $order->status }}
                            </option>
                        @endforeach
                    </select>
                    @error('sales_order_id') <div class="text-danger small">{{ $message }}</div> @enderror
                    <div class="form-text">
                        Only orders that still owe something are listed. The list takes each line's undelivered quantity.
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}"
                           placeholder="Who is walking it, and when">
                    @error('notes') <div class="text-danger small">{{ $message }}</div> @enderror
                </div>
            </div>
        </section>

        <section class="erp-card">
            <header class="erp-card-head">
                <h2 class="erp-card-title">Lines, if there is no order</h2>
                <span class="erp-chip erp-chip-outline">{{ $blankRows }} blank row(s)</span>
            </header>
            <p class="erp-td-muted small mb-2">
                Fill as many rows as the walk needs; blank rows are ignored. The same product twice is added up rather
                than walked twice.
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
            <a class="btn btn-outline-secondary" href="{{ route('inventory.pick-lists.index') }}">Cancel</a>
            <button class="btn btn-primary" type="submit">
                <i class="bi bi-basket" aria-hidden="true"></i> Create the pick list
            </button>
        </div>
    </form>
@endsection
