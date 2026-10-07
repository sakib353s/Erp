@extends('layouts.app')

@section('page_title', 'Packaging Management')

@section('content')
    <div class="erp-page-head">
        <div>
            <h1 class="erp-h1">Packaging Management</h1>
            <p class="erp-page-sub">Packaging items are real stock: each consumption posts a PACK_CONSUME movement valued from the stock layers (never a typed-in price), charges the cost onto the order, and books COGS/inventory only when the perpetual sales-cost rule is configured — nothing here invents packaging stock.</p>
        </div>
    </div>

    @if (session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if ($errors->has('packaging'))
        <div class="alert alert-warning">{{ $errors->first('packaging') }}</div>
    @endif

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Packaging position</h2>
        <div class="row text-center">
            <div class="col">
                <div class="text-muted small">Packaging types</div>
                <span class="fw-semibold">{{ $types->count() }}</span>
            </div>
            <div class="col">
                <div class="text-muted small">Consumptions</div>
                <span class="fw-semibold">{{ $usage->count() }}</span>
            </div>
            <div class="col">
                <div class="text-muted small">Consumed cost (BDT)</div>
                <span class="fw-semibold">{{ number_format($totalCost, 2) }}</span>
            </div>
        </div>
        <p class="small text-muted mb-0 mt-2">Consumed cost = sum of packaging_usage rows valued by the ledger's layer FIFO.</p>
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Add packaging type</h2>
        <form method="POST" action="{{ route('sales.delivery.packaging.types.store') }}" class="row g-2">
            @csrf
            <div class="col-md-2">
                <label class="form-label" for="code">Code</label>
                <input class="form-control" id="code" name="code" maxlength="32" required value="{{ old('code') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label" for="name">Name</label>
                <input class="form-control" id="name" name="name" maxlength="120" required value="{{ old('name') }}">
            </div>
            <div class="col-md-5">
                <label class="form-label" for="product_id">Stocked product</label>
                <select class="form-select" id="product_id" name="product_id" required>
                    <option value="">Select product…</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}" @selected((string) old('product_id') === (string) $product->id)>
                            {{ $product->sku }} — {{ $product->name }}
                        </option>
                    @endforeach
                </select>
                @error('product_id')
                    <div class="text-danger small">{{ $message }}</div>
                @enderror
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Add type</button>
            </div>
        </form>
        @if ($products->isEmpty())
            <p class="small text-muted mb-0 mt-2">No active stock-managed products yet — create one under Inventory first; packaging never invents its own stock.</p>
        @endif
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Consume packaging for an order</h2>
        @if ($types->isEmpty())
            <p class="text-muted mb-0">No packaging types yet — add a type above to start consuming packaging against orders.</p>
        @elseif ($orders->isEmpty())
            <p class="text-muted mb-0">No orders yet — packaging is consumed against a real order with a warehouse.</p>
        @else
            <form method="POST" action="{{ route('sales.delivery.packaging.store') }}" class="row g-2">
                @csrf
                <div class="col-md-4">
                    <label class="form-label" for="sales_order_id">Order</label>
                    <select class="form-select" id="sales_order_id" name="sales_order_id" required>
                        <option value="">Select order…</option>
                        @foreach ($orders as $order)
                            <option value="{{ $order->id }}" @selected((string) old('sales_order_id') === (string) $order->id)>
                                {{ $order->order_no }} ({{ $order->status }}){{ $order->warehouse_id ? '' : ' — no warehouse' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="packaging_type_id">Packaging type</label>
                    <select class="form-select" id="packaging_type_id" name="packaging_type_id" required>
                        <option value="">Select packaging…</option>
                        @foreach ($types as $type)
                            <option value="{{ $type->id }}" @selected((string) old('packaging_type_id') === (string) $type->id)>
                                {{ $type->code }} — {{ $type->name }}{{ $type->is_active ? '' : ' (inactive)' }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="qty">Qty</label>
                    <input class="form-control" id="qty" name="qty" type="number" step="0.001" min="0.001" required value="{{ old('qty') }}">
                </div>
                <div class="col-md-2 d-grid align-self-end">
                    <button class="btn btn-primary" type="submit">Consume</button>
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notes</label>
                    <input class="form-control" id="notes" name="notes" maxlength="500" value="{{ old('notes') }}">
                </div>
            </form>
            <p class="small text-muted mb-0 mt-2">The unit cost comes from the stock layers at consumption time — insufficient stock is refused with the ledger's own reason, and the order must have a warehouse.</p>
        @endif
    </div>

    <div class="erp-card mb-3">
        <h2 class="erp-h3">Consumption history</h2>
        @if ($usage->isEmpty())
            <p class="text-muted mb-0">No packaging consumed yet — consumption appears here as it is recorded.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Consumed at</th>
                            <th>Order</th>
                            <th>Packaging</th>
                            <th>Warehouse</th>
                            <th class="text-end">Qty</th>
                            <th class="text-end">Unit cost</th>
                            <th class="text-end">Total cost</th>
                            <th>Movement</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($usage as $row)
                            <tr>
                                <td>{{ $row->consumed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                <td>{{ $row->order?->order_no ?? '—' }}</td>
                                <td>
                                    {{ $row->packagingType?->code ?? '—' }}
                                    <span class="small text-muted">{{ $row->packagingType?->name ?? '' }}</span>
                                </td>
                                <td>{{ $row->warehouse?->name ?? '—' }}</td>
                                <td class="text-end">{{ number_format((float) $row->qty, 3) }}</td>
                                <td class="text-end">{{ number_format((float) $row->unit_cost, 4) }}</td>
                                <td class="text-end">{{ number_format((float) $row->total_cost, 2) }}</td>
                                <td>{{ $row->stock_movement_id ? '#'.$row->stock_movement_id : '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div class="erp-card">
        <h2 class="erp-h3">Packaging types</h2>
        @if ($types->isEmpty())
            <p class="text-muted mb-0">No packaging types yet — every type maps to a real stock-managed product.</p>
        @else
            <div class="table-responsive">
                <table class="table erp-table align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Name</th>
                            <th>Product</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($types as $type)
                            <tr>
                                <td>{{ $type->code }}</td>
                                <td>{{ $type->name }}</td>
                                <td>{{ $type->product?->sku ?? '—' }} <span class="small text-muted">{{ $type->product?->name ?? '' }}</span></td>
                                <td>
                                    @if ($type->is_active)
                                        <span class="badge bg-success">Active</span>
                                    @else
                                        <span class="badge bg-secondary">Inactive</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
